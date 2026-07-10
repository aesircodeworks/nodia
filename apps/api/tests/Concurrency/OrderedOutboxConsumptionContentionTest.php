<?php

declare(strict_types=1);

use App\Support\Outbox\Enums\OutboxDeliveryStatus;
use App\Support\Outbox\EventTypeRegistry;
use App\Support\Outbox\Jobs\ProcessOutboxDelivery;
use App\Support\Outbox\Models\OutboxDelivery;
use App\Support\Outbox\OrderedConsumption;
use App\Support\Outbox\OutboxRecorder;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;
use Tests\Support\Outbox\FixtureDomainEvent;
use Tests\Support\Outbox\FixtureDomainEventPayload;
use Tests\Support\Outbox\IdempotentTestSubscriber;
use Tests\Support\Outbox\OrderedTestSubscriber;

/*
 * Stage-04 plan Slice 4 concurrency: two workers racing on out-of-order
 * events for one aggregate apply effects in outbox sequence order
 * (system-design 9.2). Each forked worker retries until its delivery is
 * processed so a deferred successor eventually runs after the predecessor.
 */

beforeEach(function (): void {
    MigratedDatabase::ensure();
    ensureOutboxTestEffectsTable();
    app()->forgetInstance(SubscriberRegistry::class);

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    app(EventTypeRegistry::class)->register(FixtureDomainEvent::TYPE);
    registerOrderedOutboxSubscriber();

    config()->set('outbox.stability_window_seconds', 5);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('outbox_deliveries')->where('tenant_id', $this->tenantId)->delete();
        DB::table('outbox_events')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    DB::table(IdempotentTestSubscriber::EFFECTS_TABLE)->delete();
    app()->forgetInstance(SubscriberRegistry::class);
    app()->forgetScopedInstances();
});

it('applies same-aggregate out-of-order deliveries in sequence order under parallel workers', function (): void {
    Queue::fake();

    $this->freezeTime();

    $aggregateId = Str::uuid7()->toString();

    $first = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(OutboxRecorder::class)->record(new FixtureDomainEvent(
            tenantId: $this->tenantId,
            aggregateId: $aggregateId,
            payload: new FixtureDomainEventPayload($aggregateId, 'Seq lower'),
        )),
    );

    $second = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(OutboxRecorder::class)->record(new FixtureDomainEvent(
            tenantId: $this->tenantId,
            aggregateId: $aggregateId,
            payload: new FixtureDomainEventPayload($aggregateId, 'Seq higher'),
        )),
    );

    expect($second->sequence)->toBeGreaterThan($first->sequence);

    // Workers start only after the stability window so readiness is purely
    // about predecessor order under the race.
    $this->travel(config()->integer('outbox.stability_window_seconds') + 1)->seconds();

    $firstId = $first->id;
    $secondId = $second->id;
    $subscriber = OrderedTestSubscriber::NAME;
    $lowerSequence = (int) $first->sequence;
    $higherSequence = (int) $second->sequence;

    // Launch the higher-sequence worker first in the argument list and give
    // the lower-sequence worker a brief head-start delay in the opposite
    // direction is not needed: ParallelRunner barriers both. Each retries
    // until processed so a deferred higher sequence eventually completes.
    $results = ParallelRunner::runEach(
        function (PDO $pdo) use ($secondId, $subscriber): bool {
            return processUntilDeliveryProcessed($secondId, $subscriber);
        },
        function (PDO $pdo) use ($firstId, $subscriber): bool {
            return processUntilDeliveryProcessed($firstId, $subscriber);
        },
    );

    expect($results)->toBe([true, true]);

    $applied = OrderedTestSubscriber::durableAppliedSequences();

    expect($applied)->toBe([$lowerSequence, $higherSequence]);

    $statuses = app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($firstId, $secondId, $subscriber): array {
        return [
            OutboxDelivery::query()->where('outbox_event_id', $firstId)->where('subscriber', $subscriber)->firstOrFail()->status,
            OutboxDelivery::query()->where('outbox_event_id', $secondId)->where('subscriber', $subscriber)->firstOrFail()->status,
        ];
    });

    expect($statuses)->toBe([OutboxDeliveryStatus::Processed, OutboxDeliveryStatus::Processed]);
});

/**
 * Direct handle() calls (no Redis worker). Ordered deferrals are no-op
 * releases without a queue job instance, so the loop re-invokes until the
 * predecessor unlocks readiness or the attempt budget is spent.
 */
function processUntilDeliveryProcessed(string $eventId, string $subscriber, int $maxAttempts = 80): bool
{
    for ($i = 0; $i < $maxAttempts; $i++) {
        app(ProcessOutboxDelivery::class, [
            'eventId' => $eventId,
            'subscriber' => $subscriber,
        ])->handle(
            app(TenantTransaction::class),
            app(SubscriberRegistry::class),
            app(OrderedConsumption::class),
        );

        $processed = app(TenantTransaction::class)->asPlatform(
            fn (): bool => OutboxDelivery::query()
                ->where('outbox_event_id', $eventId)
                ->where('subscriber', $subscriber)
                ->where('status', OutboxDeliveryStatus::Processed)
                ->exists(),
        );

        if ($processed) {
            return true;
        }

        usleep(20_000);
    }

    return false;
}
