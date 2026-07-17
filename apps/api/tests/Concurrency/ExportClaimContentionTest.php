<?php

use App\Models\User;
use App\Reporting\Enums\ExportStatus;
use App\Reporting\Models\Export;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-11 plan, Data model "exports": the pending to processing claim
 * guards the exactly-one-worker invariant through a single conditional
 * UPDATE checked by affected-row count, never read-then-write. Two
 * parallel workers racing Export::claim() on the same pending row admit
 * exactly one; the loser's affected-row count comes back zero and it
 * takes no side effect (stage-11 plan, TDD sequencing Slice 8; mirroring
 * DailySalesProjectionContentionTest's own real-PostgreSQL discipline).
 * Written before App\Reporting\Models\Export exists per the master
 * plan's non-negotiable.
 */
const WORKERS = 2;

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('exports')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        DB::table('users')->where('email', 'like', '%export-claim-contention.example')->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

it('admits exactly one worker when WORKERS parallel claims race one pending export', function (): void {
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $exportId = app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): string {
        $user = User::factory()->create(['email' => 'requester@export-claim-contention.example']);

        return Export::factory()->create([
            'tenant_id' => $tenantId,
            'requested_by_user_id' => $user->id,
        ])->id;
    });

    $results = ParallelRunner::run(
        WORKERS,
        fn (): bool => app(TenantTransaction::class)->asTenant($tenantId, fn (): bool => Export::claim($exportId)),
    );

    $export = app(TenantTransaction::class)->asTenant($tenantId, fn () => Export::query()->findOrFail($exportId));

    $counts = array_count_values(array_map(fn (bool $won): string => $won ? 'won' : 'lost', $results));

    expect($counts['won'] ?? 0)->toBe(1)
        ->and($counts['lost'] ?? 0)->toBe(WORKERS - 1)
        ->and($export->status)->toBe(ExportStatus::Processing);
});
