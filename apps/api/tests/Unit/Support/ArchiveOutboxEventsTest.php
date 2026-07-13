<?php

use App\Support\Archive\Actions\ArchiveOutboxEvents;
use App\Support\Archive\Enums\ArchiveSegmentSource;
use App\Support\Archive\Models\ArchiveSegment;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-12 plan, Slice 4, task breakdown item 10 Unit: segment
 * boundaries are deterministic from config('retention.
 * outbox_archive_batch_size') alone (same eligible row set, same
 * batch size, same split every run), and the archiver refuses to run
 * (and deletes nothing) when object storage is unreachable, or when
 * either config knob is non-positive.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

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
});

/**
 * @return array{id: string}
 */
function insertArchivableOutboxEvent(string $tenantId, string $occurredAt): array
{
    $id = (string) Str::uuid7();

    app(TenantTransaction::class)->asTenant($tenantId, function () use ($id, $tenantId, $occurredAt): void {
        DB::table('outbox_events')->insert([
            'id' => $id,
            'type' => 'FixtureEvent',
            'tenant_id' => $tenantId,
            'aggregate_type' => 'fixture',
            'aggregate_id' => (string) Str::uuid7(),
            'correlation_id' => 'archive-unit-correlation',
            'occurred_at' => $occurredAt,
            'payload' => json_encode(['source' => 'archive-unit'], JSON_THROW_ON_ERROR),
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    });

    return ['id' => $id];
}

it('archives exactly the configured batch size, keeping the segment boundary deterministic', function (): void {
    $this->freezeTime();
    Storage::fake(config()->string('retention.archive_disk'));
    config()->set('retention.outbox_archival_days', 2);
    config()->set('retention.outbox_archive_batch_size', 3);

    $old = now()->subDays(3)->toDateTimeString();

    for ($i = 0; $i < 5; $i++) {
        insertArchivableOutboxEvent($this->tenantId, $old);
    }

    $archived = app(ArchiveOutboxEvents::class)();

    expect($archived)->toBe(3);

    $segment = ArchiveSegment::query()->where('source', ArchiveSegmentSource::OutboxEvents)->sole();
    expect($segment->row_count)->toBe(3);

    $remaining = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DB::table('outbox_events')->where('tenant_id', $this->tenantId)->count(),
    );

    expect($remaining)->toBe(2);

    $secondArchived = app(ArchiveOutboxEvents::class)();

    expect($secondArchived)->toBe(2)
        ->and(ArchiveSegment::query()->where('source', ArchiveSegmentSource::OutboxEvents)->count())->toBe(2);

    $remainingAfterSecond = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DB::table('outbox_events')->where('tenant_id', $this->tenantId)->count(),
    );

    expect($remainingAfterSecond)->toBe(0);
});

it('refuses to run and deletes no row when object storage is unreachable', function (): void {
    $this->freezeTime();
    config()->set('retention.outbox_archival_days', 2);
    config()->set('retention.outbox_archive_batch_size', 100);

    $row = insertArchivableOutboxEvent($this->tenantId, now()->subDays(3)->toDateTimeString());

    $disk = config()->string('retention.archive_disk');
    Storage::shouldReceive('disk')->with($disk)->andThrow(new RuntimeException('connection refused'));

    expect(fn () => app(ArchiveOutboxEvents::class)())->toThrow(RuntimeException::class);

    $stillThere = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DB::table('outbox_events')->where('id', $row['id'])->exists(),
    );

    expect($stillThere)->toBeTrue()
        ->and(ArchiveSegment::query()->where('source', ArchiveSegmentSource::OutboxEvents)->count())->toBe(0);
});

dataset('non-positive windows', [0, -1, -30]);

it('refuses to run when the configured retention window is zero or negative', function (int $days): void {
    config()->set('retention.outbox_archival_days', $days);
    config()->set('retention.outbox_archive_batch_size', 100);

    expect(fn () => app(ArchiveOutboxEvents::class)())->toThrow(RuntimeException::class);
    expect(ArchiveSegment::query()->where('source', ArchiveSegmentSource::OutboxEvents)->count())->toBe(0);
})->with('non-positive windows');

it('refuses to run when the configured batch size is zero or negative', function (int $size): void {
    config()->set('retention.outbox_archival_days', 2);
    config()->set('retention.outbox_archive_batch_size', $size);

    expect(fn () => app(ArchiveOutboxEvents::class)())->toThrow(RuntimeException::class);
    expect(ArchiveSegment::query()->where('source', ArchiveSegmentSource::OutboxEvents)->count())->toBe(0);
})->with('non-positive windows');
