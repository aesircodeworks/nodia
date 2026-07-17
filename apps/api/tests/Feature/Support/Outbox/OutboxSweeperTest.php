<?php

declare(strict_types=1);

use App\Support\Outbox\Enums\OutboxDeliveryStatus;
use App\Support\Outbox\Jobs\ProcessOutboxDelivery;
use App\Support\Outbox\Models\OutboxDelivery;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-04 plan Slice 3 feature: reconciliation sweeper re-enqueues
 * stranded pending deliveries past the grace window (measured from
 * coalesce(last_enqueued_at, created_at)), never re-enqueues processed,
 * and only considers events older than the stability window so sequence
 * gaps are not treated as final (system-design 9.1).
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    config()->set('outbox.stability_window_seconds', 5);
    config()->set('outbox.sweeper_grace_seconds', 60);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('outbox_deliveries')->where('tenant_id', $this->tenantId)->delete();
        DB::table('outbox_events')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    app()->forgetScopedInstances();
});

/**
 * @return array{event: OutboxEvent, delivery: OutboxDelivery}
 */
function strandedPendingDelivery(
    string $tenantId,
    string $subscriber = 'sweep_test_subscriber',
    ?OutboxDeliveryStatus $status = null,
    bool $withEnqueueTimestamp = false,
): array {
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $subscriber, $status, $withEnqueueTimestamp): array {
        $event = OutboxEvent::query()->create([
            'type' => 'FixtureEvent',
            'tenant_id' => $tenantId,
            'aggregate_type' => 'fixture',
            'aggregate_id' => Str::uuid7()->toString(),
            'correlation_id' => 'sweep-feature-correlation',
            'occurred_at' => now(),
            'payload' => ['source' => 'sweep-feature'],
        ]);

        $delivery = OutboxDelivery::query()->create([
            'outbox_event_id' => $event->id,
            'tenant_id' => $tenantId,
            'subscriber' => $subscriber,
            'status' => $status ?? OutboxDeliveryStatus::Pending,
            'last_enqueued_at' => $withEnqueueTimestamp ? now() : null,
            'processed_at' => $status === OutboxDeliveryStatus::Processed ? now() : null,
        ]);

        return ['event' => $event, 'delivery' => $delivery];
    });
}

it('re-enqueues a pending delivery with no enqueue once grace elapses from created_at', function () {
    $this->freezeTime();

    $fixture = strandedPendingDelivery($this->tenantId);
    $delivery = $fixture['delivery'];
    $event = $fixture['event'];

    expect($delivery->last_enqueued_at)->toBeNull();

    Queue::fake();

    $this->travel(config()->integer('outbox.sweeper_grace_seconds'))->seconds();

    Artisan::call('outbox:sweep');

    Queue::assertPushed(ProcessOutboxDelivery::class, 1);
    Queue::assertPushed(ProcessOutboxDelivery::class, function (ProcessOutboxDelivery $job) use ($event, $delivery): bool {
        return $job->eventId === $event->id && $job->subscriber === $delivery->subscriber;
    });

    $row = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxDelivery::query()->whereKey($delivery->id)->firstOrFail(),
    );

    expect($row->status)->toBe(OutboxDeliveryStatus::Pending)
        ->and($row->last_enqueued_at)->not->toBeNull()
        ->and($row->last_enqueued_at->getTimestamp())->toBe(now()->getTimestamp());
});

it('does not re-enqueue a pending delivery still inside the grace window', function () {
    $this->freezeTime();

    $fixture = strandedPendingDelivery($this->tenantId);
    $delivery = $fixture['delivery'];

    Queue::fake();

    $this->travel(config()->integer('outbox.sweeper_grace_seconds') - 1)->seconds();

    Artisan::call('outbox:sweep');

    Queue::assertNothingPushed();

    $row = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxDelivery::query()->whereKey($delivery->id)->firstOrFail(),
    );

    expect($row->last_enqueued_at)->toBeNull();
});

it('never re-enqueues a processed delivery', function () {
    $this->freezeTime();

    $fixture = strandedPendingDelivery(
        $this->tenantId,
        status: OutboxDeliveryStatus::Processed,
    );
    $delivery = $fixture['delivery'];

    Queue::fake();

    $this->travel(config()->integer('outbox.sweeper_grace_seconds') + 120)->seconds();

    Artisan::call('outbox:sweep');

    Queue::assertNothingPushed();

    $row = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxDelivery::query()->whereKey($delivery->id)->firstOrFail(),
    );

    expect($row->status)->toBe(OutboxDeliveryStatus::Processed)
        ->and($row->last_enqueued_at)->toBeNull();
});

it('does not treat events younger than the stability window as final', function () {
    // Grace shorter than stability so the clock can pass grace while the
    // event is still inside the stability window (system-design 9.1:
    // a lower sequence can still commit).
    config()->set('outbox.sweeper_grace_seconds', 10);
    config()->set('outbox.stability_window_seconds', 60);

    $this->freezeTime();

    $fixture = strandedPendingDelivery($this->tenantId);
    $delivery = $fixture['delivery'];

    Queue::fake();

    $this->travel(30)->seconds();

    Artisan::call('outbox:sweep');

    Queue::assertNothingPushed();

    $row = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxDelivery::query()->whereKey($delivery->id)->firstOrFail(),
    );

    expect($row->last_enqueued_at)->toBeNull();

    $this->travel(40)->seconds();

    Artisan::call('outbox:sweep');

    Queue::assertPushed(ProcessOutboxDelivery::class, 1);

    $row = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxDelivery::query()->whereKey($delivery->id)->firstOrFail(),
    );

    expect($row->last_enqueued_at)->not->toBeNull();
});

it('re-enqueues from last_enqueued_at when set rather than created_at', function () {
    $this->freezeTime();

    $fixture = strandedPendingDelivery($this->tenantId, withEnqueueTimestamp: true);
    $delivery = $fixture['delivery'];

    Queue::fake();

    // Past stability (5s) and past created_at+grace would fire if the
    // window used created_at, but last_enqueued_at was set at t0 and we
    // only travel half the grace window from it after refreshing enqueue.
    $this->travel(config()->integer('outbox.sweeper_grace_seconds') - 1)->seconds();

    Artisan::call('outbox:sweep');

    Queue::assertNothingPushed();

    $this->travel(1)->seconds();

    Artisan::call('outbox:sweep');

    Queue::assertPushed(ProcessOutboxDelivery::class, 1);

    $row = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxDelivery::query()->whereKey($delivery->id)->firstOrFail(),
    );

    expect($row->last_enqueued_at->getTimestamp())->toBe(now()->getTimestamp());
});
