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
use App\Support\Outbox\ProjectionLock;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\Outbox\FixtureDomainEvent;
use Tests\Support\Outbox\FixtureDomainEventPayload;
use Tests\Support\Outbox\IdempotentTestSubscriber;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-12 plan, Slice 4, task breakdown item 10: the outbox archiver.
 * Rows past config('retention.outbox_archival_days') with no pending
 * delivery are exported to one NDJSON object storage segment, the
 * archive_segments manifest row records range, count, and checksum, and
 * the source outbox_events and outbox_deliveries rows are deleted, in
 * per-tenant transactions, only once the uploaded object's checksum
 * verifies (system-design 9.1). A row with any pending delivery, and
 * every row after it in sequence order, is left for a later run so an
 * archived segment's range never has a live row inside it.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    Storage::fake(config()->string('retention.archive_disk'));

    // QUEUE_CONNECTION=sync in testing would otherwise deliver every
    // recorded event's subscribers synchronously at record time
    // (App\Support\Outbox\OutboxRecorder::record()'s dispatchAfterCommit),
    // making it impossible to leave a delivery pending on purpose. Every
    // delivery this file cares about is driven explicitly through
    // processArchivalDelivery() instead.
    Queue::fake();

    app()->forgetInstance(SubscriberRegistry::class);
    app(EventTypeRegistry::class)->register(FixtureDomainEvent::TYPE);

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
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

function recordArchivableOutboxEvent(string $tenantId, string $displayName): OutboxEvent
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => app(OutboxRecorder::class)->record(new FixtureDomainEvent(
            tenantId: $tenantId,
            aggregateId: Str::uuid7()->toString(),
            payload: new FixtureDomainEventPayload(Str::uuid7()->toString(), $displayName),
        )),
    );
}

function processArchivalDelivery(string $eventId, string $subscriber): void
{
    (new ProcessOutboxDelivery($eventId, $subscriber))->handle(
        app(TenantTransaction::class),
        app(SubscriberRegistry::class),
        app(OrderedConsumption::class),
        app(ProjectionLock::class),
    );
}

function archivalOutboxEventExists(string $tenantId, string $id): bool
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => DB::table('outbox_events')->where('id', $id)->exists(),
    );
}

function archivalOutboxDeliveryExists(string $tenantId, string $eventId): bool
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => DB::table('outbox_deliveries')->where('outbox_event_id', $eventId)->exists(),
    );
}

it('archives old rows and their processed deliveries to a checksummed segment, leaving young rows untouched', function (): void {
    app(SubscriberRegistry::class)->register(
        IdempotentTestSubscriber::NAME,
        [FixtureDomainEvent::TYPE],
        new IdempotentTestSubscriber,
    );

    $this->freezeTime();
    config()->set('retention.outbox_archival_days', 2);
    config()->set('retention.outbox_archive_batch_size', 100);

    $old = recordArchivableOutboxEvent($this->tenantId, 'old');
    processArchivalDelivery($old->id, IdempotentTestSubscriber::NAME);

    $this->travel(3)->days();

    $young = recordArchivableOutboxEvent($this->tenantId, 'young');
    processArchivalDelivery($young->id, IdempotentTestSubscriber::NAME);

    $archived = app(ArchiveOutboxEvents::class)();

    expect($archived)->toBe(1)
        ->and(archivalOutboxEventExists($this->tenantId, $old->id))->toBeFalse()
        ->and(archivalOutboxDeliveryExists($this->tenantId, $old->id))->toBeFalse()
        ->and(archivalOutboxEventExists($this->tenantId, $young->id))->toBeTrue()
        ->and(archivalOutboxDeliveryExists($this->tenantId, $young->id))->toBeTrue();

    $segment = ArchiveSegment::query()->where('source', ArchiveSegmentSource::OutboxEvents)->sole();

    expect($segment->source)->toBe(ArchiveSegmentSource::OutboxEvents)
        ->and($segment->range_from)->toBe((string) $old->sequence)
        ->and($segment->range_to)->toBe((string) $old->sequence)
        ->and($segment->row_count)->toBe(1)
        ->and($segment->checksum)->not->toBeEmpty()
        ->and($segment->archived_at)->not->toBeNull();

    $disk = Storage::disk(config()->string('retention.archive_disk'));
    expect($disk->exists($segment->object_key))->toBeTrue();

    $content = $disk->get($segment->object_key);
    expect(hash('sha256', $content))->toBe($segment->checksum);

    $lines = array_values(array_filter(explode("\n", trim($content))));
    expect($lines)->toHaveCount(1);

    $decoded = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
    expect($decoded['id'])->toBe($old->id)
        ->and($decoded['sequence'])->toBe((int) $old->sequence)
        ->and($decoded['tenant_id'])->toBe($this->tenantId)
        ->and($decoded['payload']['display_name'])->toBe('old');
});

it('is a no-op that writes no manifest when nothing is past the window', function (): void {
    $this->freezeTime();
    config()->set('retention.outbox_archival_days', 2);
    config()->set('retention.outbox_archive_batch_size', 100);

    $young = recordArchivableOutboxEvent($this->tenantId, 'young');

    $archived = app(ArchiveOutboxEvents::class)();

    expect($archived)->toBe(0)
        ->and(ArchiveSegment::query()->where('source', ArchiveSegmentSource::OutboxEvents)->count())->toBe(0)
        ->and(archivalOutboxEventExists($this->tenantId, $young->id))->toBeTrue();
});

it('leaves the rows in place and writes no manifest when the upload fails', function (): void {
    $this->freezeTime();
    config()->set('retention.outbox_archival_days', 2);
    config()->set('retention.outbox_archive_batch_size', 100);

    $old = recordArchivableOutboxEvent($this->tenantId, 'old');
    $this->travel(3)->days();

    $disk = config()->string('retention.archive_disk');
    $adapter = Mockery::mock(Filesystem::class);
    $adapter->shouldReceive('put')->andThrow(new RuntimeException('connection refused'));
    Storage::shouldReceive('disk')->with($disk)->andReturn($adapter);

    expect(fn () => app(ArchiveOutboxEvents::class)())->toThrow(RuntimeException::class);

    expect(ArchiveSegment::query()->where('source', ArchiveSegmentSource::OutboxEvents)->count())->toBe(0)
        ->and(archivalOutboxEventExists($this->tenantId, $old->id))->toBeTrue();
});

it('stops the batch at the first row with a pending delivery, keeping the archived range contiguous', function (): void {
    app(SubscriberRegistry::class)->register(
        IdempotentTestSubscriber::NAME,
        [FixtureDomainEvent::TYPE],
        new IdempotentTestSubscriber,
    );

    $this->freezeTime();
    config()->set('retention.outbox_archival_days', 2);
    config()->set('retention.outbox_archive_batch_size', 100);

    $first = recordArchivableOutboxEvent($this->tenantId, 'first');
    processArchivalDelivery($first->id, IdempotentTestSubscriber::NAME);

    // Left pending on purpose: never delivered.
    $blocked = recordArchivableOutboxEvent($this->tenantId, 'blocked');

    $after = recordArchivableOutboxEvent($this->tenantId, 'after');
    processArchivalDelivery($after->id, IdempotentTestSubscriber::NAME);

    $this->travel(3)->days();

    $archived = app(ArchiveOutboxEvents::class)();

    expect($archived)->toBe(1)
        ->and(archivalOutboxEventExists($this->tenantId, $first->id))->toBeFalse()
        ->and(archivalOutboxEventExists($this->tenantId, $blocked->id))->toBeTrue()
        ->and(archivalOutboxDeliveryExists($this->tenantId, $blocked->id))->toBeTrue()
        ->and(archivalOutboxEventExists($this->tenantId, $after->id))->toBeTrue();

    $segment = ArchiveSegment::query()->where('source', ArchiveSegmentSource::OutboxEvents)->sole();
    expect($segment->row_count)->toBe(1)
        ->and($segment->range_from)->toBe((string) $first->sequence)
        ->and($segment->range_to)->toBe((string) $first->sequence);
});

it('is a no-op that writes no manifest when the only eligible row has a pending delivery', function (): void {
    app(SubscriberRegistry::class)->register(
        IdempotentTestSubscriber::NAME,
        [FixtureDomainEvent::TYPE],
        new IdempotentTestSubscriber,
    );

    $this->freezeTime();
    config()->set('retention.outbox_archival_days', 2);
    config()->set('retention.outbox_archive_batch_size', 100);

    $blocked = recordArchivableOutboxEvent($this->tenantId, 'blocked');
    $this->travel(3)->days();

    $archived = app(ArchiveOutboxEvents::class)();

    expect($archived)->toBe(0)
        ->and(ArchiveSegment::query()->where('source', ArchiveSegmentSource::OutboxEvents)->count())->toBe(0)
        ->and(archivalOutboxEventExists($this->tenantId, $blocked->id))->toBeTrue();
});

it('does not re-enqueue a delivery for an event the archiver already removed', function (): void {
    app(SubscriberRegistry::class)->register(
        IdempotentTestSubscriber::NAME,
        [FixtureDomainEvent::TYPE],
        new IdempotentTestSubscriber,
    );

    $this->freezeTime();
    config()->set('retention.outbox_archival_days', 2);
    config()->set('retention.outbox_archive_batch_size', 100);
    config()->set('outbox.sweeper_grace_seconds', 60);
    config()->set('outbox.stability_window_seconds', 5);

    $event = recordArchivableOutboxEvent($this->tenantId, 'swept');
    processArchivalDelivery($event->id, IdempotentTestSubscriber::NAME);

    $this->travel(3)->days();

    $archived = app(ArchiveOutboxEvents::class)();
    expect($archived)->toBe(1);

    Queue::fake();
    $this->travel(config()->integer('outbox.sweeper_grace_seconds') + 1)->seconds();

    Artisan::call('outbox:sweep');

    Queue::assertNothingPushed();
});
