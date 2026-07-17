<?php

declare(strict_types=1);

use App\Support\Archive\Actions\ArchiveOutboxEvents;
use App\Support\Archive\Enums\ArchiveSegmentSource;
use App\Support\Archive\Models\ArchiveSegment;
use App\Support\Outbox\EventTypeRegistry;
use App\Support\Outbox\Jobs\ProcessOutboxDelivery;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OrderedConsumption;
use App\Support\Outbox\OutboxRecorder;
use App\Support\Outbox\OutboxReplay;
use App\Support\Outbox\ProjectionLock;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\Outbox\FixtureDomainEvent;
use Tests\Support\Outbox\FixtureDomainEventPayload;
use Tests\Support\Outbox\ProjectionTestSubscriber;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-12 plan, Slice 4, task breakdown item 10 feature: OutboxReplay
 * reads archived segments so a projection rebuild spans the archive
 * boundary. Re-proves the stage-04 rebuild-equivalence test (tests/
 * Feature/Support/Outbox/OutboxReplayTest.php, "rebuilds a fresh
 * projection identical to incremental state") across a history where the
 * earliest events have already moved to an archive_segments-backed
 * object storage segment and only the later events remain live.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    Storage::fake(config()->string('retention.archive_disk'));

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

    ArchiveSegment::query()->where('source', ArchiveSegmentSource::OutboxEvents)->delete();
    app()->forgetInstance(SubscriberRegistry::class);
    app()->forgetScopedInstances();
});

/**
 * @return list<OutboxEvent>
 */
function recordArchiveBoundarySeries(string $tenantId, string $label, int $count = 2): array
{
    $recorded = [];

    for ($i = 0; $i < $count; $i++) {
        $recorded[] = app(TenantTransaction::class)->asTenant(
            $tenantId,
            fn () => app(OutboxRecorder::class)->record(new FixtureDomainEvent(
                tenantId: $tenantId,
                aggregateId: Str::uuid7()->toString(),
                payload: new FixtureDomainEventPayload(Str::uuid7()->toString(), "{$label}-{$i}"),
            )),
        );
    }

    return $recorded;
}

function processArchiveBoundaryDelivery(string $eventId, string $subscriber): void
{
    (new ProcessOutboxDelivery($eventId, $subscriber))->handle(
        app(TenantTransaction::class),
        app(SubscriberRegistry::class),
        app(OrderedConsumption::class),
        app(ProjectionLock::class),
    );
}

/**
 * @param  list<OutboxEvent>  $events
 * @return list<int>
 */
function archiveBoundarySortedSequences(array $events): array
{
    $sequences = array_map(static fn (OutboxEvent $event): int => (int) $event->sequence, $events);
    sort($sequences);

    return $sequences;
}

it('rebuilds a fresh projection identical to incremental state when replay spans the archive boundary', function (): void {
    $incremental = new ProjectionTestSubscriber;
    app(SubscriberRegistry::class)->register(
        ProjectionTestSubscriber::NAME,
        [FixtureDomainEvent::TYPE],
        $incremental,
    );

    Queue::fake();
    $this->freezeTime();

    config()->set('retention.outbox_archival_days', 2);
    config()->set('retention.outbox_archive_batch_size', 100);
    config()->set('outbox.stability_window_seconds', 5);

    $early = recordArchiveBoundarySeries($this->tenantId, 'early');
    expect($early)->toHaveCount(2);

    $this->travel(config()->integer('outbox.stability_window_seconds') + 1)->seconds();

    foreach ($early as $event) {
        processArchiveBoundaryDelivery($event->id, ProjectionTestSubscriber::NAME);
    }

    // Past the archival window, but the two early events already have
    // their only delivery processed, so they are archival-eligible.
    $this->travel(3)->days();

    $archived = app(ArchiveOutboxEvents::class)();
    expect($archived)->toBe(2);

    $live = recordArchiveBoundarySeries($this->tenantId, 'live');
    expect($live)->toHaveCount(2);

    $this->travel(config()->integer('outbox.stability_window_seconds') + 1)->seconds();

    foreach ($live as $event) {
        processArchiveBoundaryDelivery($event->id, ProjectionTestSubscriber::NAME);
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

    $all = array_merge($early, $live);

    expect($count)->toBe(4)
        ->and($rebuild->snapshot())->toBe($incrementalSnapshot)
        ->and($rebuild->visitOrder())->toBe(archiveBoundarySortedSequences($all));
});

it('accepts an inclusive starting sequence that falls inside an archived segment', function (): void {
    Queue::fake();
    $this->freezeTime();

    config()->set('retention.outbox_archival_days', 2);
    config()->set('retention.outbox_archive_batch_size', 100);
    config()->set('outbox.stability_window_seconds', 5);

    $early = recordArchiveBoundarySeries($this->tenantId, 'early', 3);
    expect($early)->toHaveCount(3);

    $this->travel(3)->days();

    $archived = app(ArchiveOutboxEvents::class)();
    expect($archived)->toBe(3);

    $subscriber = new ProjectionTestSubscriber;
    app(SubscriberRegistry::class)->register(
        ProjectionTestSubscriber::NAME,
        [FixtureDomainEvent::TYPE],
        $subscriber,
    );

    $fromSequence = (int) $early[1]->sequence;

    $count = app(OutboxReplay::class)->replay(ProjectionTestSubscriber::NAME, fromSequence: $fromSequence);

    expect($count)->toBe(2)
        ->and($subscriber->visitOrder())->toBe([
            (int) $early[1]->sequence,
            (int) $early[2]->sequence,
        ]);
});

it('fails replay when a selected archive object is missing', function (): void {
    $this->freezeTime();
    config()->set('retention.outbox_archival_days', 2);
    config()->set('retention.outbox_archive_batch_size', 100);

    recordArchiveBoundarySeries($this->tenantId, 'missing', 1);
    $this->travel(3)->days();
    app(ArchiveOutboxEvents::class)();

    $segment = ArchiveSegment::query()->where('source', ArchiveSegmentSource::OutboxEvents)->sole();
    Storage::disk(config()->string('retention.archive_disk'))->delete($segment->object_key);

    app(SubscriberRegistry::class)->register(
        ProjectionTestSubscriber::NAME,
        [FixtureDomainEvent::TYPE],
        new ProjectionTestSubscriber,
    );

    expect(fn () => app(OutboxReplay::class)->replay(ProjectionTestSubscriber::NAME))
        ->toThrow(RuntimeException::class);
});

it('fails replay when a selected archive object does not match its manifest checksum', function (): void {
    $this->freezeTime();
    config()->set('retention.outbox_archival_days', 2);
    config()->set('retention.outbox_archive_batch_size', 100);

    recordArchiveBoundarySeries($this->tenantId, 'corrupt', 1);
    $this->travel(3)->days();
    app(ArchiveOutboxEvents::class)();

    $segment = ArchiveSegment::query()->where('source', ArchiveSegmentSource::OutboxEvents)->sole();
    Storage::disk(config()->string('retention.archive_disk'))->put($segment->object_key, "corrupted\n");

    app(SubscriberRegistry::class)->register(
        ProjectionTestSubscriber::NAME,
        [FixtureDomainEvent::TYPE],
        new ProjectionTestSubscriber,
    );

    expect(fn () => app(OutboxReplay::class)->replay(ProjectionTestSubscriber::NAME))
        ->toThrow(RuntimeException::class, 'checksum');
});

it('deduplicates a legacy event repeated across overlapping archive segments', function (): void {
    $this->freezeTime();
    config()->set('retention.outbox_archival_days', 2);
    config()->set('retention.outbox_archive_batch_size', 100);

    recordArchiveBoundarySeries($this->tenantId, 'duplicate', 1);
    $this->travel(3)->days();
    app(ArchiveOutboxEvents::class)();

    $segment = ArchiveSegment::query()->where('source', ArchiveSegmentSource::OutboxEvents)->sole();
    $disk = Storage::disk(config()->string('retention.archive_disk'));
    $content = $disk->get($segment->object_key);
    $duplicateKey = 'archive-segments/outbox_events/legacy-duplicate.ndjson';
    $disk->put($duplicateKey, $content);

    ArchiveSegment::query()->create([
        'source' => ArchiveSegmentSource::OutboxEvents,
        'range_from' => $segment->range_from,
        'range_to' => (string) ((int) $segment->range_to + 1),
        'object_key' => $duplicateKey,
        'row_count' => $segment->row_count,
        'checksum' => $segment->checksum,
        'archived_at' => now(),
        'completed_at' => now(),
    ]);

    $subscriber = new ProjectionTestSubscriber;
    app(SubscriberRegistry::class)->register(
        ProjectionTestSubscriber::NAME,
        [FixtureDomainEvent::TYPE],
        $subscriber,
    );

    expect(app(OutboxReplay::class)->replay(ProjectionTestSubscriber::NAME))->toBe(1)
        ->and($subscriber->appliedCount())->toBe(1);
});
