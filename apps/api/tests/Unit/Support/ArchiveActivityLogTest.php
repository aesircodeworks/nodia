<?php

use App\Support\Archive\Actions\ArchiveActivityLog;
use App\Support\Archive\Enums\ArchiveSegmentSource;
use App\Support\Archive\Models\ArchiveSegment;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-12 plan, Slice 3, task breakdown item 9 Unit: the archiver
 * refuses to run when object storage is unreachable rather than
 * deleting, and a zero or negative retention window refuses to run
 * rather than archiving (and deleting) everything.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    $id = $this->rowId ?? null;

    if ($id !== null) {
        app(TenantTransaction::class)->asPlatform(function () use ($id): void {
            DB::selectOne('select set_config(?, ?, true)', [
                'app.activity_log_prune_cutoff',
                now()->addYear()->toIso8601String(),
            ]);
            DB::table('activity_log')->where('id', $id)->delete();
        });
    }

    ArchiveSegment::query()->where('source', ArchiveSegmentSource::ActivityLog)->delete();
});

it('refuses to run and deletes no row when object storage is unreachable', function (): void {
    $this->freezeTime();
    config()->set('retention.activity_log_days', 2);

    $this->rowId = (string) Str::uuid7();
    $rowId = $this->rowId;

    app(TenantTransaction::class)->asPlatform(function () use ($rowId): void {
        DB::table('activity_log')->insert([
            'id' => $rowId,
            'tenant_id' => (string) Str::uuid7(),
            'log_name' => 'default',
            'description' => 'unreachable storage probe',
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);
    });

    $disk = config()->string('retention.archive_disk');
    Storage::shouldReceive('disk')->with($disk)->andThrow(new RuntimeException('connection refused'));

    expect(fn () => app(ArchiveActivityLog::class)())->toThrow(RuntimeException::class);

    $stillThere = app(TenantTransaction::class)->asPlatform(
        fn () => DB::table('activity_log')->where('id', $rowId)->exists(),
    );

    expect($stillThere)->toBeTrue()
        ->and(ArchiveSegment::query()->where('source', ArchiveSegmentSource::ActivityLog)->count())->toBe(0);
});

dataset('non-positive windows', [0, -1, -30]);

it('refuses to run and archives nothing when the configured window is zero or negative', function (int $days): void {
    config()->set('retention.activity_log_days', $days);

    expect(fn () => app(ArchiveActivityLog::class)())->toThrow(RuntimeException::class);

    expect(ArchiveSegment::query()->where('source', ArchiveSegmentSource::ActivityLog)->count())->toBe(0);
})->with('non-positive windows');
