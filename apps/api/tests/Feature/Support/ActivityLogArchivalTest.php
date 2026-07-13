<?php

use App\Payments\Enums\LedgerAccount;
use App\Payments\Enums\LedgerDirection;
use App\Support\Archive\Actions\ArchiveActivityLog;
use App\Support\Archive\Enums\ArchiveSegmentSource;
use App\Support\Archive\Models\ArchiveSegment;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-12 plan, Slice 3, task breakdown item 9: the activity log
 * archive-then-prune command. Rows past
 * config('retention.activity_log_days') are exported to one NDJSON
 * object storage segment, the archive_segments manifest row records
 * range, count, and checksum, and the source rows are deleted only
 * once the uploaded object's checksum verifies (system-design 14.2,
 * 14.3). No pruner, including this one, ever touches financial
 * records.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    Storage::fake(config()->string('retention.archive_disk'));
    archivableActivityLogIdRegistry(reset: true);
});

afterEach(function (): void {
    $ids = archivableActivityLogIdRegistry();

    if ($ids !== []) {
        app(TenantTransaction::class)->asPlatform(function () use ($ids): void {
            DB::selectOne('select set_config(?, ?, true)', [
                'app.activity_log_prune_cutoff',
                now()->addYear()->toIso8601String(),
            ]);
            DB::table('activity_log')->whereIn('id', $ids)->delete();
        });
    }

    ArchiveSegment::query()->where('source', ArchiveSegmentSource::ActivityLog)->delete();
});

/**
 * A plain static registry, not a test-case property: this file's helper
 * functions run outside any it()/beforeEach closure's own $this binding,
 * so they cannot rely on Pest's test() proxy for mutable state (its
 * HigherOrderTapProxy forwards method calls but not property writes).
 * Returned by reference so insertArchivableActivityLogRow() can append
 * to the same underlying static array.
 *
 * @return list<string>
 */
function &archivableActivityLogIdRegistry(bool $reset = false): array
{
    static $ids = [];

    if ($reset) {
        $ids = [];
    }

    return $ids;
}

/**
 * @return array{id: string, tenantId: string}
 */
function insertArchivableActivityLogRow(string $createdAt, string $description): array
{
    $id = (string) Str::uuid7();
    $tenantId = (string) Str::uuid7();

    app(TenantTransaction::class)->asPlatform(function () use ($id, $tenantId, $createdAt, $description): void {
        DB::table('activity_log')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'log_name' => 'default',
            'description' => $description,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    });

    $ids = &archivableActivityLogIdRegistry();
    $ids[] = $id;

    return ['id' => $id, 'tenantId' => $tenantId];
}

function activityLogRowExists(string $id): bool
{
    return app(TenantTransaction::class)->asPlatform(
        fn () => DB::table('activity_log')->where('id', $id)->exists(),
    );
}

it('archives old rows to a checksummed segment and deletes them only after verification, leaving young rows untouched', function (): void {
    $this->freezeTime();
    $now = now();
    config()->set('retention.activity_log_days', 2);

    $old = insertArchivableActivityLogRow($now->copy()->subDays(3)->toDateTimeString(), 'old entry');
    $young = insertArchivableActivityLogRow($now->copy()->subHours(1)->toDateTimeString(), 'young entry');

    $archived = app(ArchiveActivityLog::class)();

    expect($archived)->toBe(1)
        ->and(activityLogRowExists($old['id']))->toBeFalse()
        ->and(activityLogRowExists($young['id']))->toBeTrue();

    $segment = ArchiveSegment::query()->where('source', ArchiveSegmentSource::ActivityLog)->sole();

    expect($segment->source)->toBe(ArchiveSegmentSource::ActivityLog)
        ->and($segment->row_count)->toBe(1)
        ->and($segment->checksum)->not->toBeEmpty()
        ->and($segment->archived_at)->not->toBeNull();

    $disk = Storage::disk(config()->string('retention.archive_disk'));
    expect($disk->exists($segment->object_key))->toBeTrue();

    $content = $disk->get($segment->object_key);
    expect(hash('sha256', $content))->toBe($segment->checksum)
        ->and($content)->toContain('old entry')
        ->and($content)->not->toContain('young entry');
});

it('is a no-op that writes no manifest when nothing is past the window', function (): void {
    $this->freezeTime();
    config()->set('retention.activity_log_days', 2);

    $young = insertArchivableActivityLogRow(now()->copy()->subHours(1)->toDateTimeString(), 'young entry');

    $archived = app(ArchiveActivityLog::class)();

    expect($archived)->toBe(0)
        ->and(ArchiveSegment::query()->where('source', ArchiveSegmentSource::ActivityLog)->count())->toBe(0)
        ->and(activityLogRowExists($young['id']))->toBeTrue();
});

it('leaves the rows in place and writes no manifest when the upload fails', function (): void {
    $this->freezeTime();
    $now = now();
    config()->set('retention.activity_log_days', 2);

    $old = insertArchivableActivityLogRow($now->copy()->subDays(3)->toDateTimeString(), 'old entry');

    $disk = config()->string('retention.archive_disk');
    $adapter = Mockery::mock(Filesystem::class);
    $adapter->shouldReceive('put')->andThrow(new RuntimeException('connection refused'));
    Storage::shouldReceive('disk')->with($disk)->andReturn($adapter);

    expect(fn () => app(ArchiveActivityLog::class)())->toThrow(RuntimeException::class);

    expect(ArchiveSegment::query()->where('source', ArchiveSegmentSource::ActivityLog)->count())->toBe(0)
        ->and(activityLogRowExists($old['id']))->toBeTrue();
});

it('never touches ledger entries while archiving activity log rows', function (): void {
    $this->freezeTime();
    $now = now();
    config()->set('retention.activity_log_days', 2);

    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $entryId = (string) Str::uuid7();
    app(TenantTransaction::class)->asTenant($tenantId, function () use ($entryId, $tenantId): void {
        DB::table('ledger_entries')->insert([
            'id' => $entryId,
            'tenant_id' => $tenantId,
            'account' => LedgerAccount::GatewayReceivable->value,
            'direction' => LedgerDirection::Debit->value,
            'amount' => 1_000,
            'currency' => 'USD',
            'reference_type' => 'payment',
            'reference_id' => (string) Str::uuid7(),
            'source_event_id' => (string) Str::uuid7(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    insertArchivableActivityLogRow($now->copy()->subDays(3)->toDateTimeString(), 'financial-adjacent entry');

    app(ArchiveActivityLog::class)();

    $stillThere = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => DB::table('ledger_entries')->where('id', $entryId)->first(),
    );

    expect($stillThere)->not->toBeNull()
        ->and((int) $stillThere->amount)->toBe(1_000);

    DB::statement('truncate ledger_entries');
    app(TenantTransaction::class)->asPlatform(fn () => Tenant::query()->whereKey($tenantId)->delete());
});
