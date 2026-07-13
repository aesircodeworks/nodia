<?php

declare(strict_types=1);

use App\Support\Audit\Models\ActivityLogEntry;
use App\Support\Outbox\Enums\OutboxDeliveryStatus;
use App\Support\Outbox\Jobs\ProcessOutboxDelivery;
use App\Support\Outbox\Models\OutboxDelivery;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OutboxSubscriber;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\Outbox\IdempotentTestSubscriber;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-12 plan Slice 5, task breakdown item 11: outbox:replay-failed is
 * dry-run by default, lists the outbox's dead-lettered ProcessOutboxDelivery
 * failed_jobs rows (Stage 4's dead letter table) alongside any stranded
 * pending deliveries (OutboxSweeper's own definition), --execute
 * re-enqueues each exactly once, every invocation is activity-logged under
 * the sentinel platform tenant naming the operator, and --operator is
 * required.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    config()->set('outbox.stability_window_seconds', 5);
    config()->set('outbox.sweeper_grace_seconds', 60);

    // Exactly one JobFailed listener per test: writes a real failed_jobs
    // row the same way Illuminate\Queue\Console\WorkCommand does for a
    // live worker, so fixtures carry an authentic serialized payload
    // rather than a hand-rolled one.
    Event::forget(JobFailed::class);
    Event::listen(JobFailed::class, function (JobFailed $event): void {
        app('queue.failer')->log(
            $event->connectionName,
            $event->job->getQueue(),
            $event->job->getRawBody(),
            $event->exception,
        );
    });
});

afterEach(function (): void {
    DB::table('failed_jobs')->delete();

    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('outbox_deliveries')->where('tenant_id', $this->tenantId)->delete();
        DB::table('outbox_events')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    forgetOutboxSubscriberRegistry();
    app()->forgetScopedInstances();
});

/**
 * @return array{event: OutboxEvent, delivery: OutboxDelivery}
 */
function replayFixtureDelivery(
    string $tenantId,
    string $subscriber,
    bool $withEnqueueTimestamp = false,
): array {
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $subscriber, $withEnqueueTimestamp): array {
        $event = OutboxEvent::query()->create([
            'type' => 'FixtureEvent',
            'tenant_id' => $tenantId,
            'aggregate_type' => 'fixture',
            'aggregate_id' => Str::uuid7()->toString(),
            'correlation_id' => 'replay-failed-feature-correlation',
            'occurred_at' => now(),
            'payload' => ['source' => 'replay-failed-feature'],
        ]);

        $delivery = OutboxDelivery::query()->create([
            'outbox_event_id' => $event->id,
            'tenant_id' => $tenantId,
            'subscriber' => $subscriber,
            'status' => OutboxDeliveryStatus::Pending,
            'last_enqueued_at' => $withEnqueueTimestamp ? now() : null,
        ]);

        return ['event' => $event, 'delivery' => $delivery];
    });
}

/**
 * Registers a subscriber that always throws, dispatches its delivery job
 * for real (sync queue, no faking), and lets the JobFailed listener
 * registered in beforeEach persist a genuine failed_jobs row. Leaves the
 * delivery pending: the tenant transaction wrapping markProcessed() and
 * the handler rolls back together with the thrown exception.
 */
function dealSyntheticOutboxFailure(OutboxEvent $event, string $subscriber): void
{
    $throwing = new class implements OutboxSubscriber
    {
        public function handle(OutboxEvent $event): void
        {
            throw new RuntimeException('synthetic failure for outbox:replay-failed fixture');
        }
    };

    app(SubscriberRegistry::class)->register($subscriber, ['FixtureEvent'], $throwing);

    try {
        ProcessOutboxDelivery::dispatch($event->id, $subscriber);
    } catch (Throwable) {
        // Expected: the synthetic failure is what populates failed_jobs.
    }
}

it('lists matching failed jobs and stranded deliveries in dry run without side effects', function (): void {
    $this->freezeTime();

    $failedFixture = replayFixtureDelivery($this->tenantId, 'replay_failed_job_subscriber');
    dealSyntheticOutboxFailure($failedFixture['event'], 'replay_failed_job_subscriber');

    expect(DB::table('failed_jobs')->count())->toBe(1);

    $strandedFixture = replayFixtureDelivery($this->tenantId, 'replay_stranded_subscriber');
    $this->travel(config()->integer('outbox.sweeper_grace_seconds'))->seconds();

    Queue::fake();

    Artisan::call('outbox:replay-failed', ['--operator' => 'ana']);

    expect(Artisan::output())
        ->toContain('1 matching failed job')
        ->toContain('1 stranded delivery');

    Queue::assertNothingPushed();
    expect(Queue::pushedRaw())->toBeEmpty();

    expect(DB::table('failed_jobs')->count())->toBe(1);

    $delivery = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxDelivery::query()->whereKey($strandedFixture['delivery']->id)->firstOrFail(),
    );

    expect($delivery->status)->toBe(OutboxDeliveryStatus::Pending)
        ->and($delivery->last_enqueued_at)->toBeNull();
});

it('re-enqueues each matching failed job and stranded delivery exactly once on execute', function (): void {
    $this->freezeTime();

    $failedFixture = replayFixtureDelivery($this->tenantId, 'replay_failed_job_subscriber_2');
    dealSyntheticOutboxFailure($failedFixture['event'], 'replay_failed_job_subscriber_2');

    $strandedFixture = replayFixtureDelivery($this->tenantId, 'replay_stranded_subscriber_2');
    $this->travel(config()->integer('outbox.sweeper_grace_seconds'))->seconds();

    Queue::fake();

    Artisan::call('outbox:replay-failed', ['--operator' => 'ana', '--execute' => true]);

    // The stranded delivery re-enqueues through ProcessOutboxDelivery::
    // dispatch(), the same path OutboxSweeper::reenqueue() always takes.
    Queue::assertPushed(ProcessOutboxDelivery::class, 1);
    Queue::assertPushed(ProcessOutboxDelivery::class, function (ProcessOutboxDelivery $job) use ($strandedFixture): bool {
        return $job->eventId === $strandedFixture['event']->id && $job->subscriber === 'replay_stranded_subscriber_2';
    });

    // The failed job re-enqueues through Queue::pushRaw(), mirroring
    // Illuminate\Queue\Console\RetryCommand: its original payload is
    // pushed back unread, never reconstructed as a fresh dispatch.
    $rawPushes = Queue::pushedRaw();
    expect($rawPushes)->toHaveCount(1);

    $rawPayload = json_decode((string) $rawPushes->first()['payload'], true);
    expect($rawPayload['displayName'])->toBe(ProcessOutboxDelivery::class)
        ->and($rawPayload['data']['command'])->toContain($failedFixture['event']->id)
        ->and($rawPayload['data']['command'])->toContain('replay_failed_job_subscriber_2');

    expect(DB::table('failed_jobs')->count())->toBe(0);

    $delivery = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxDelivery::query()->whereKey($strandedFixture['delivery']->id)->firstOrFail(),
    );

    expect($delivery->status)->toBe(OutboxDeliveryStatus::Pending)
        ->and($delivery->last_enqueued_at)->not->toBeNull();

    $entry = app(TenantTransaction::class)->asPlatform(
        fn () => ActivityLogEntry::query()
            ->where('event', 'outbox_replay_failed_invoked')
            ->where('properties->operator', 'ana')
            ->where('properties->execute', true)
            ->latest('created_at')
            ->first(),
    );

    expect($entry)->not->toBeNull()
        ->and($entry->properties['failed_jobs_matched'])->toBe(1)
        ->and($entry->properties['stranded_deliveries_matched'])->toBe(1)
        ->and($entry->properties['failed_jobs_reenqueued'])->toBe(1)
        ->and($entry->properties['stranded_deliveries_reenqueued'])->toBe(1);
});

it('proves an accidental double replay is harmless through consumer idempotence', function (): void {
    $this->freezeTime();

    $subscriber = registerIdempotentOutboxSubscriber();

    $fixture = replayFixtureDelivery($this->tenantId, IdempotentTestSubscriber::NAME);
    $this->travel(config()->integer('outbox.sweeper_grace_seconds'))->seconds();

    Artisan::call('outbox:replay-failed', ['--operator' => 'ana', '--execute' => true]);

    expect($subscriber->effectCount())->toBe(1);

    $delivery = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxDelivery::query()->whereKey($fixture['delivery']->id)->firstOrFail(),
    );

    expect($delivery->status)->toBe(OutboxDeliveryStatus::Processed);

    // An accidental duplicate replay of the same event/subscriber pair
    // (a second manual run, a race with the standing outbox:sweep sweeper
    // or a live worker) must never repeat the effect.
    processOutboxDeliveryTwice($fixture['event']->id, IdempotentTestSubscriber::NAME);

    expect($subscriber->effectCount())->toBe(1);
});

it('records an activity log entry naming the operator, arguments, and counts for a dry run', function (): void {
    $failedFixture = replayFixtureDelivery($this->tenantId, 'replay_failed_job_subscriber_3');
    dealSyntheticOutboxFailure($failedFixture['event'], 'replay_failed_job_subscriber_3');

    Artisan::call('outbox:replay-failed', ['--operator' => 'priya']);

    $entry = app(TenantTransaction::class)->asPlatform(
        fn () => ActivityLogEntry::query()
            ->where('event', 'outbox_replay_failed_invoked')
            ->where('properties->operator', 'priya')
            ->first(),
    );

    expect($entry)->not->toBeNull()
        ->and($entry->tenant_id)->toBe(config()->string('tenancy.platform_tenant_id'))
        ->and($entry->properties['operator'])->toBe('priya')
        ->and($entry->properties['execute'])->toBeFalse()
        ->and($entry->properties['failed_jobs_matched'])->toBe(1)
        ->and($entry->properties['stranded_deliveries_matched'])->toBe(0)
        ->and($entry->properties['failed_jobs_reenqueued'])->toBe(0)
        ->and($entry->properties['stranded_deliveries_reenqueued'])->toBe(0);
});

it('refuses to run without --operator and records nothing', function (): void {
    $before = app(TenantTransaction::class)->asPlatform(
        fn () => ActivityLogEntry::query()->where('event', 'outbox_replay_failed_invoked')->count(),
    );

    $exitCode = Artisan::call('outbox:replay-failed');

    expect($exitCode)->not->toBe(0);

    $after = app(TenantTransaction::class)->asPlatform(
        fn () => ActivityLogEntry::query()->where('event', 'outbox_replay_failed_invoked')->count(),
    );

    expect($after)->toBe($before);
});

it('refuses to run with a blank --operator', function (): void {
    $exitCode = Artisan::call('outbox:replay-failed', ['--operator' => '   ']);

    expect($exitCode)->not->toBe(0);
});
