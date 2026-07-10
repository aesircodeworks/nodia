<?php

declare(strict_types=1);

use App\Support\Outbox\Enums\OutboxDeliveryStatus;
use App\Support\Outbox\EventTypeRegistry;
use App\Support\Outbox\Jobs\ProcessOutboxDelivery;
use App\Support\Outbox\Models\OutboxDelivery;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OrderedConsumption;
use App\Support\Outbox\OutboxRecorder;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\Outbox\FixtureDomainEvent;
use Tests\Support\Outbox\FixtureDomainEventPayload;
use Tests\Support\Outbox\OrderedTestSubscriber;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-04 plan Slice 4 feature: ordered-consumption helper defers an
 * event whose same-aggregate predecessor is unprocessed (job released,
 * delivery stays pending) and processes after the predecessor completes;
 * a different aggregate does not block; events younger than the stability
 * window are not processed (system-design 9.1 / 9.2).
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    app()->forgetInstance(SubscriberRegistry::class);

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    app(EventTypeRegistry::class)->register(FixtureDomainEvent::TYPE);

    config()->set('outbox.stability_window_seconds', 5);
    config()->set('outbox.ordered_defer_seconds', 15);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('outbox_deliveries')->where('tenant_id', $this->tenantId)->delete();
        DB::table('outbox_events')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    app()->forgetInstance(SubscriberRegistry::class);
    app()->forgetScopedInstances();
});

/**
 * @return array{first: OutboxEvent, second: OutboxEvent}
 */
function recordOrderedPair(string $tenantId, string $aggregateId): array
{
    $first = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => app(OutboxRecorder::class)->record(new FixtureDomainEvent(
            tenantId: $tenantId,
            aggregateId: $aggregateId,
            payload: new FixtureDomainEventPayload($aggregateId, 'First'),
        )),
    );

    $second = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => app(OutboxRecorder::class)->record(new FixtureDomainEvent(
            tenantId: $tenantId,
            aggregateId: $aggregateId,
            payload: new FixtureDomainEventPayload($aggregateId, 'Second'),
        )),
    );

    expect($second->sequence)->toBeGreaterThan($first->sequence);

    return ['first' => $first, 'second' => $second];
}

function deliveryStatus(string $tenantId, string $eventId, string $subscriber = OrderedTestSubscriber::NAME): OutboxDeliveryStatus
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => OutboxDelivery::query()
            ->where('outbox_event_id', $eventId)
            ->where('subscriber', $subscriber)
            ->firstOrFail()
            ->status,
    );
}

function runOrderedJob(string $eventId, bool $fakeQueue = true): ProcessOutboxDelivery
{
    $job = new ProcessOutboxDelivery($eventId, OrderedTestSubscriber::NAME);

    if ($fakeQueue) {
        $job->withFakeQueueInteractions();
    }

    $job->handle(
        app(TenantTransaction::class),
        app(SubscriberRegistry::class),
        app(OrderedConsumption::class),
    );

    return $job;
}

it('defers a same-aggregate successor while the predecessor is unprocessed, then processes after the predecessor completes', function () {
    $subscriber = registerOrderedOutboxSubscriber();
    Queue::fake();
    $this->freezeTime();

    $aggregateId = Str::uuid7()->toString();
    $pair = recordOrderedPair($this->tenantId, $aggregateId);

    // Past the stability window so only the predecessor gate remains.
    $this->travel(config()->integer('outbox.stability_window_seconds') + 1)->seconds();

    $deferred = runOrderedJob($pair['second']->id);

    $deferred->assertReleased(config()->integer('outbox.ordered_defer_seconds'));
    expect($subscriber->effectCount())->toBe(0)
        ->and(deliveryStatus($this->tenantId, $pair['second']->id))->toBe(OutboxDeliveryStatus::Pending)
        ->and(deliveryStatus($this->tenantId, $pair['first']->id))->toBe(OutboxDeliveryStatus::Pending);

    $predecessor = runOrderedJob($pair['first']->id);
    $predecessor->assertNotReleased();

    expect($subscriber->effectCount())->toBe(1)
        ->and($subscriber->processedEventIds())->toBe([$pair['first']->id])
        ->and(deliveryStatus($this->tenantId, $pair['first']->id))->toBe(OutboxDeliveryStatus::Processed);

    $successor = runOrderedJob($pair['second']->id);
    $successor->assertNotReleased();

    expect($subscriber->effectCount())->toBe(2)
        ->and($subscriber->processedEventIds())->toBe([$pair['first']->id, $pair['second']->id])
        ->and($subscriber->processedSequences())->toBe([(int) $pair['first']->sequence, (int) $pair['second']->sequence])
        ->and(deliveryStatus($this->tenantId, $pair['second']->id))->toBe(OutboxDeliveryStatus::Processed);
});

it('does not block on an unprocessed event for a different aggregate', function () {
    $subscriber = registerOrderedOutboxSubscriber();
    Queue::fake();
    $this->freezeTime();

    $aggregateA = Str::uuid7()->toString();
    $aggregateB = Str::uuid7()->toString();

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(OutboxRecorder::class)->record(new FixtureDomainEvent(
            tenantId: $this->tenantId,
            aggregateId: $aggregateA,
            payload: new FixtureDomainEventPayload($aggregateA, 'Other aggregate pending'),
        )),
    );

    $target = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(OutboxRecorder::class)->record(new FixtureDomainEvent(
            tenantId: $this->tenantId,
            aggregateId: $aggregateB,
            payload: new FixtureDomainEventPayload($aggregateB, 'Target aggregate'),
        )),
    );

    $this->travel(config()->integer('outbox.stability_window_seconds') + 1)->seconds();

    $job = runOrderedJob($target->id);
    $job->assertNotReleased();

    expect($subscriber->effectCount())->toBe(1)
        ->and($subscriber->processedEventIds())->toBe([$target->id])
        ->and(deliveryStatus($this->tenantId, $target->id))->toBe(OutboxDeliveryStatus::Processed);
});

it('does not process an ordered event younger than the stability window', function () {
    $subscriber = registerOrderedOutboxSubscriber();
    Queue::fake();
    $this->freezeTime();

    $aggregateId = Str::uuid7()->toString();

    $recorded = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(OutboxRecorder::class)->record(new FixtureDomainEvent(
            tenantId: $this->tenantId,
            aggregateId: $aggregateId,
            payload: new FixtureDomainEventPayload($aggregateId, 'Young'),
        )),
    );

    $job = runOrderedJob($recorded->id);
    $job->assertReleased(config()->integer('outbox.ordered_defer_seconds'));

    expect($subscriber->effectCount())->toBe(0)
        ->and(deliveryStatus($this->tenantId, $recorded->id))->toBe(OutboxDeliveryStatus::Pending);

    $this->travel(config()->integer('outbox.stability_window_seconds') + 1)->seconds();

    $ready = runOrderedJob($recorded->id);
    $ready->assertNotReleased();

    expect($subscriber->effectCount())->toBe(1)
        ->and(deliveryStatus($this->tenantId, $recorded->id))->toBe(OutboxDeliveryStatus::Processed);
});

it('completes without releasing on the final attempt so the sweeper can re-enqueue', function () {
    $subscriber = registerOrderedOutboxSubscriber();
    Queue::fake();
    $this->freezeTime();

    $aggregateId = Str::uuid7()->toString();

    $recorded = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(OutboxRecorder::class)->record(new FixtureDomainEvent(
            tenantId: $this->tenantId,
            aggregateId: $aggregateId,
            payload: new FixtureDomainEventPayload($aggregateId, 'Exhaust'),
        )),
    );

    $job = new ProcessOutboxDelivery($recorded->id, OrderedTestSubscriber::NAME);
    $job->tries = 1;
    $job->withFakeQueueInteractions();
    // FakeJob defaults attempts=1; with tries=1 this is the final attempt.
    $job->handle(
        app(TenantTransaction::class),
        app(SubscriberRegistry::class),
        app(OrderedConsumption::class),
    );

    $job->assertNotReleased();
    $job->assertNotFailed();

    expect($subscriber->effectCount())->toBe(0)
        ->and(deliveryStatus($this->tenantId, $recorded->id))->toBe(OutboxDeliveryStatus::Pending);
});
