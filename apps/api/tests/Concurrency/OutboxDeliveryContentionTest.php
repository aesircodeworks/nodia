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

/*
 * Stage-04 plan Slice 2 concurrency: two parallel workers processing the
 * same delivery produce exactly one effect; the conditional pending-to-
 * processed UPDATE admits exactly one winner by affected-row count
 * (event-conventions; master plan test-first rule 2).
 *
 * Each forked worker inherits the booted application
 * (Tests\Concurrency\Support\ParallelRunner), so the job handle is called
 * directly. Durable effect rows prove the losing worker never ran the
 * subscriber under nodia_app.
 */

beforeEach(function (): void {
    MigratedDatabase::ensure();
    ensureOutboxTestEffectsTable();
    app()->forgetInstance(SubscriberRegistry::class);

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    app(EventTypeRegistry::class)->register(FixtureDomainEvent::TYPE);
    registerIdempotentOutboxSubscriber();
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

it('resolves two parallel workers on one delivery to exactly one subscriber effect', function (): void {
    Queue::fake();

    $aggregateId = Str::uuid7()->toString();
    $event = new FixtureDomainEvent(
        tenantId: $this->tenantId,
        aggregateId: $aggregateId,
        payload: new FixtureDomainEventPayload($aggregateId, 'Concurrency Fixture'),
    );

    $recorded = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(OutboxRecorder::class)->record($event),
    );

    $eventId = $recorded->id;
    $subscriber = IdempotentTestSubscriber::NAME;

    ParallelRunner::run(
        2,
        function (PDO $pdo) use ($eventId, $subscriber): bool {
            app(ProcessOutboxDelivery::class, [
                'eventId' => $eventId,
                'subscriber' => $subscriber,
            ])->handle(
                app(TenantTransaction::class),
                app(SubscriberRegistry::class),
                app(OrderedConsumption::class),
            );

            return true;
        },
    );

    $delivery = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxDelivery::query()
            ->where('outbox_event_id', $eventId)
            ->where('subscriber', $subscriber)
            ->firstOrFail(),
    );

    expect(IdempotentTestSubscriber::durableEffectCount($eventId))->toBe(1)
        ->and($delivery->status)->toBe(OutboxDeliveryStatus::Processed);
});
