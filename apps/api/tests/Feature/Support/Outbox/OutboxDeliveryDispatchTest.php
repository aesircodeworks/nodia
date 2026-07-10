<?php

declare(strict_types=1);

use App\Support\Outbox\Enums\OutboxDeliveryStatus;
use App\Support\Outbox\EventTypeRegistry;
use App\Support\Outbox\Jobs\ProcessOutboxDelivery;
use App\Support\Outbox\Models\OutboxDelivery;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OutboxRecorder;
use App\Support\Outbox\OutboxSubscriber;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\Outbox\FixtureDomainEvent;
use Tests\Support\Outbox\FixtureDomainEventPayload;
use Tests\Support\Outbox\IdempotentTestSubscriber;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-04 plan Slice 2 feature: recording creates one pending delivery
 * per registered subscriber in the same transaction (rollback removes
 * both); after commit, exactly one queue job per subscriber is enqueued
 * carrying the event id (and subscriber routing key); a rolled-back
 * transaction enqueues nothing.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    app()->forgetInstance(SubscriberRegistry::class);

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    app(EventTypeRegistry::class)->register(FixtureDomainEvent::TYPE);
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

function dispatchFixtureEvent(string $tenantId): FixtureDomainEvent
{
    $aggregateId = Str::uuid7()->toString();

    return new FixtureDomainEvent(
        tenantId: $tenantId,
        aggregateId: $aggregateId,
        payload: new FixtureDomainEventPayload($aggregateId, 'Dispatch Fixture'),
    );
}

/**
 * @return list<string>
 */
function registerNamedSubscribers(string ...$names): array
{
    $registered = [];

    foreach ($names as $name) {
        app(SubscriberRegistry::class)->register(
            $name,
            [FixtureDomainEvent::TYPE],
            new class implements OutboxSubscriber
            {
                public function handle(OutboxEvent $event): void {}
            },
        );
        $registered[] = $name;
    }

    return $registered;
}

it('creates one pending delivery per registered subscriber in the same transaction', function () {
    registerNamedSubscribers('subscriber_a', 'subscriber_b');
    Queue::fake();

    $event = dispatchFixtureEvent($this->tenantId);

    $recorded = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(OutboxRecorder::class)->record($event),
    );

    $deliveries = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxDelivery::query()
            ->where('outbox_event_id', $recorded->id)
            ->orderBy('subscriber')
            ->get(),
    );

    expect($deliveries)->toHaveCount(2)
        ->and($deliveries->pluck('subscriber')->all())->toBe(['subscriber_a', 'subscriber_b'])
        ->and($deliveries->every(fn (OutboxDelivery $d): bool => $d->status === OutboxDeliveryStatus::Pending))->toBeTrue()
        ->and($deliveries->every(fn (OutboxDelivery $d): bool => $d->tenant_id === $this->tenantId))->toBeTrue()
        ->and($deliveries->every(fn (OutboxDelivery $d): bool => $d->last_enqueued_at !== null))->toBeTrue();
});

it('removes both the event and its deliveries when the producing transaction rolls back', function () {
    registerNamedSubscribers('subscriber_a');
    Queue::fake();

    $event = dispatchFixtureEvent($this->tenantId);

    try {
        app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($event): void {
            app(OutboxRecorder::class)->record($event);
            throw new RuntimeException('force rollback after record');
        });
    } catch (RuntimeException) {
        // expected
    }

    $counts = app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($event): array {
        return [
            'events' => OutboxEvent::query()->where('aggregate_id', $event->aggregateId())->count(),
            'deliveries' => OutboxDelivery::query()->where('tenant_id', $this->tenantId)->count(),
        ];
    });

    expect($counts['events'])->toBe(0)
        ->and($counts['deliveries'])->toBe(0);

    Queue::assertNothingPushed();
});

it('enqueues exactly one job per subscriber after commit carrying the event id and subscriber', function () {
    registerNamedSubscribers('subscriber_a', 'subscriber_b');
    Queue::fake();

    $event = dispatchFixtureEvent($this->tenantId);

    $recorded = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(OutboxRecorder::class)->record($event),
    );

    Queue::assertPushed(ProcessOutboxDelivery::class, 2);
    Queue::assertPushed(ProcessOutboxDelivery::class, function (ProcessOutboxDelivery $job) use ($recorded): bool {
        return $job->eventId === $recorded->id && $job->subscriber === 'subscriber_a';
    });
    Queue::assertPushed(ProcessOutboxDelivery::class, function (ProcessOutboxDelivery $job) use ($recorded): bool {
        return $job->eventId === $recorded->id && $job->subscriber === 'subscriber_b';
    });
});

it('enqueues nothing when the producing transaction rolls back', function () {
    registerNamedSubscribers('subscriber_a', 'subscriber_b');
    Queue::fake();

    $event = dispatchFixtureEvent($this->tenantId);

    try {
        app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($event): void {
            app(OutboxRecorder::class)->record($event);
            throw new RuntimeException('force rollback after record');
        });
    } catch (RuntimeException) {
        // expected
    }

    Queue::assertNothingPushed();
});

it('creates no deliveries and enqueues nothing when no subscribers are registered', function () {
    Queue::fake();

    $event = dispatchFixtureEvent($this->tenantId);

    $recorded = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(OutboxRecorder::class)->record($event),
    );

    $deliveryCount = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxDelivery::query()->where('outbox_event_id', $recorded->id)->count(),
    );

    expect($deliveryCount)->toBe(0);
    Queue::assertNothingPushed();
});

it('processes a delivery end to end: runs the test subscriber once and marks processed', function () {
    $subscriber = registerIdempotentOutboxSubscriber();

    $event = dispatchFixtureEvent($this->tenantId);

    // phpunit defaults QUEUE_CONNECTION=sync, so after-commit dispatch runs
    // the job inline against real PostgreSQL (stage-04 Slice 2 e2e).
    $recorded = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(OutboxRecorder::class)->record($event),
    );

    $delivery = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxDelivery::query()
            ->where('outbox_event_id', $recorded->id)
            ->where('subscriber', IdempotentTestSubscriber::NAME)
            ->firstOrFail(),
    );

    expect($subscriber->effectCount())->toBe(1)
        ->and($subscriber->processedEventIds())->toBe([$recorded->id])
        ->and($delivery->status)->toBe(OutboxDeliveryStatus::Processed)
        ->and($delivery->processed_at)->not->toBeNull();
});

it('runs the subscriber effect exactly once when the same job is delivered twice', function () {
    $subscriber = registerIdempotentOutboxSubscriber();
    Queue::fake();

    $event = dispatchFixtureEvent($this->tenantId);

    $recorded = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(OutboxRecorder::class)->record($event),
    );

    processOutboxDeliveryTwice($recorded->id);

    $delivery = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxDelivery::query()
            ->where('outbox_event_id', $recorded->id)
            ->where('subscriber', IdempotentTestSubscriber::NAME)
            ->firstOrFail(),
    );

    expect($subscriber->effectCount())->toBe(1)
        ->and($delivery->status)->toBe(OutboxDeliveryStatus::Processed);
});
