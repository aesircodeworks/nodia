<?php

declare(strict_types=1);

use App\Support\Outbox\EventTypeRegistry;
use App\Support\Outbox\Jobs\ProcessOutboxDelivery;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OrderedConsumption;
use App\Support\Outbox\OutboxRecorder;
use App\Support\Outbox\OutboxReplay;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\Outbox\FixtureDomainEvent;
use Tests\Support\Outbox\FixtureDomainEventPayload;
use Tests\Support\Outbox\ProjectionTestSubscriber;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-04 plan Slice 5 feature: after recording a series of events across
 * aggregates and processing them incrementally into a test projection,
 * replaying from sequence zero into a fresh projection yields identical
 * state; replay visits rows in sequence order and skips nothing older
 * than the stability window; replaying twice yields the same state
 * (consumer idempotence makes replay safe).
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    app()->forgetInstance(SubscriberRegistry::class);

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    app(EventTypeRegistry::class)->register(FixtureDomainEvent::TYPE);

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

    app()->forgetInstance(SubscriberRegistry::class);
    app()->forgetScopedInstances();
});

/**
 * @return list<OutboxEvent>
 */
function recordProjectionSeries(string $tenantId, int $eventsPerAggregate = 2): array
{
    $aggregateA = Str::uuid7()->toString();
    $aggregateB = Str::uuid7()->toString();
    $recorded = [];

    foreach ([$aggregateA, $aggregateB] as $aggregateId) {
        for ($i = 0; $i < $eventsPerAggregate; $i++) {
            $recorded[] = app(TenantTransaction::class)->asTenant(
                $tenantId,
                fn () => app(OutboxRecorder::class)->record(new FixtureDomainEvent(
                    tenantId: $tenantId,
                    aggregateId: $aggregateId,
                    payload: new FixtureDomainEventPayload($aggregateId, "evt-{$i}"),
                )),
            );
        }
    }

    return $recorded;
}

function processDelivery(string $eventId, string $subscriber): void
{
    $job = new ProcessOutboxDelivery($eventId, $subscriber);
    $job->handle(
        app(TenantTransaction::class),
        app(SubscriberRegistry::class),
        app(OrderedConsumption::class),
    );
}

/**
 * @param  list<OutboxEvent>  $events
 * @return list<int>
 */
function sortedSequences(array $events): array
{
    $sequences = array_map(static fn (OutboxEvent $event): int => (int) $event->sequence, $events);
    sort($sequences);

    return $sequences;
}

it('rebuilds a fresh projection identical to incremental state when replaying from sequence zero', function () {
    $incremental = new ProjectionTestSubscriber;
    app(SubscriberRegistry::class)->register(
        ProjectionTestSubscriber::NAME,
        [FixtureDomainEvent::TYPE],
        $incremental,
    );

    Queue::fake();
    $this->freezeTime();

    $events = recordProjectionSeries($this->tenantId);
    expect($events)->toHaveCount(4);

    $this->travel(config()->integer('outbox.stability_window_seconds') + 1)->seconds();

    // Process out of sequence order so rebuild equivalence does not
    // depend on delivery order (commutative projection).
    foreach (array_reverse($events) as $event) {
        processDelivery($event->id, ProjectionTestSubscriber::NAME);
    }

    $incrementalSnapshot = $incremental->snapshot();
    expect($incremental->appliedCount())->toBe(4);

    $rebuild = new ProjectionTestSubscriber;
    app(SubscriberRegistry::class)->register(
        ProjectionTestSubscriber::REBUILD_NAME,
        [FixtureDomainEvent::TYPE],
        $rebuild,
    );

    $count = app(OutboxReplay::class)->replay(ProjectionTestSubscriber::REBUILD_NAME);

    expect($count)->toBe(4)
        ->and($rebuild->snapshot())->toBe($incrementalSnapshot)
        ->and($rebuild->visitOrder())->toBe(sortedSequences($events));
});

it('visits events in sequence order and skips nothing older than the stability window', function () {
    $subscriber = new ProjectionTestSubscriber;
    app(SubscriberRegistry::class)->register(
        ProjectionTestSubscriber::NAME,
        [FixtureDomainEvent::TYPE],
        $subscriber,
    );

    Queue::fake();
    $this->freezeTime();

    $stable = recordProjectionSeries($this->tenantId, 1);
    expect($stable)->toHaveCount(2);

    $this->travel(config()->integer('outbox.stability_window_seconds') + 1)->seconds();

    $young = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(OutboxRecorder::class)->record(new FixtureDomainEvent(
            tenantId: $this->tenantId,
            aggregateId: Str::uuid7()->toString(),
            payload: new FixtureDomainEventPayload(Str::uuid7()->toString(), 'young'),
        )),
    );

    $count = app(OutboxReplay::class)->replay(ProjectionTestSubscriber::NAME);

    expect($count)->toBe(2)
        ->and($subscriber->appliedCount())->toBe(2)
        ->and($subscriber->visitOrder())->toBe(sortedSequences($stable))
        ->and(in_array((int) $young->sequence, $subscriber->visitOrder(), true))->toBeFalse();

    // Advance past the stability window for the young event; nothing
    // stable is skipped and the previously young row is now included.
    $this->travel(config()->integer('outbox.stability_window_seconds') + 1)->seconds();

    $fresh = new ProjectionTestSubscriber;
    app(SubscriberRegistry::class)->register(
        ProjectionTestSubscriber::REBUILD_NAME,
        [FixtureDomainEvent::TYPE],
        $fresh,
    );

    $all = array_merge($stable, [$young]);
    $countAll = app(OutboxReplay::class)->replay(ProjectionTestSubscriber::REBUILD_NAME);

    expect($countAll)->toBe(3)
        ->and($fresh->visitOrder())->toBe(sortedSequences($all));
});

it('yields the same state when replaying twice (consumer idempotence)', function () {
    $subscriber = new ProjectionTestSubscriber;
    app(SubscriberRegistry::class)->register(
        ProjectionTestSubscriber::NAME,
        [FixtureDomainEvent::TYPE],
        $subscriber,
    );

    Queue::fake();
    $this->freezeTime();

    recordProjectionSeries($this->tenantId);
    $this->travel(config()->integer('outbox.stability_window_seconds') + 1)->seconds();

    $firstCount = app(OutboxReplay::class)->replay(ProjectionTestSubscriber::NAME);
    $afterFirst = $subscriber->snapshot();
    $visitAfterFirst = $subscriber->visitOrder();

    $secondCount = app(OutboxReplay::class)->replay(ProjectionTestSubscriber::NAME);

    expect($firstCount)->toBe(4)
        ->and($secondCount)->toBe(4)
        ->and($subscriber->snapshot())->toBe($afterFirst)
        ->and($subscriber->visitOrder())->toBe($visitAfterFirst)
        ->and($subscriber->appliedCount())->toBe(4);
});

it('exposes outbox:replay artisan command that drives the primitive', function () {
    $subscriber = new ProjectionTestSubscriber;
    app(SubscriberRegistry::class)->register(
        ProjectionTestSubscriber::NAME,
        [FixtureDomainEvent::TYPE],
        $subscriber,
    );

    Queue::fake();
    $this->freezeTime();

    $events = recordProjectionSeries($this->tenantId, 1);
    $this->travel(config()->integer('outbox.stability_window_seconds') + 1)->seconds();

    $exit = Artisan::call('outbox:replay', [
        'subscriber' => ProjectionTestSubscriber::NAME,
    ]);

    expect($exit)->toBe(0)
        ->and($subscriber->appliedCount())->toBe(2)
        ->and($subscriber->visitOrder())->toBe(sortedSequences($events))
        ->and(Artisan::output())->toContain('2');
});
