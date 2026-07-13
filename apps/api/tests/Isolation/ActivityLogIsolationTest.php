<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\ActivityLogFixture;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * activity_log is the append-only member of the isolation suite
 * (stage-03 plan task breakdown item 14, amended by stage-12 task
 * breakdown item 8): the standard tenant deny matrix for SELECT and
 * INSERT, plus proof that UPDATE is denied unconditionally for every
 * role and DELETE is denied for nodia_app in request context exactly as
 * before (system-design 14.2, "log rows are never updated ... inside
 * the application"), but nodia_platform now carries a narrow DELETE
 * grant restricted by the 2026_07_13_000062 migration's
 * activity_log_platform_delete policy to rows whose created_at is
 * before a session-scoped cutoff (app.activity_log_prune_cutoff) that
 * only the retention pruning command (task breakdown item 9) ever sets.
 * Ordinary platform-role usage, which never sets that cutoff, still
 * removes nothing. Assertions target the specific ids
 * ActivityLogFixture::seed() returns rather than a total row count:
 * rows from earlier tests in this file are never deletable through the
 * ordinary path and accumulate for the rest of the process (see that
 * fixture's own docblock); rows this file inserts directly to probe the
 * cutoff-scoped path are deleted by the very test that inserts them.
 */

beforeEach(function (): void {
    $this->entries = ActivityLogFixture::seed();
});

afterEach(fn () => ActivityLogFixture::clean());

it('shows a tenant only its own activity_log row', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('activity_log')->pluck('id'),
    );

    expect($ids->all())->toContain($this->entries['tenantA'])
        ->and($ids->all())->not->toContain($this->entries['tenantB']);
});

it('rejects an insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('activity_log')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => TenantFixture::TENANT_B,
            'log_name' => 'default',
            'description' => 'forged',
            'created_at' => now(),
            'updated_at' => now(),
        ]),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('isolates a raw sql query that bypasses all eloquent scoping', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::select('select id from activity_log where tenant_id = ?', [TenantFixture::TENANT_A]),
    );

    $ids = array_column($rows, 'id');

    expect($ids)->toContain($this->entries['tenantA'])
        ->and($ids)->not->toContain($this->entries['tenantB']);
});

it('lets nodia_platform read every tenant activity_log row without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('activity_log')->pluck('id'));

    expect($ids->all())->toContain($this->entries['tenantA'], $this->entries['tenantB']);
});

it('lets nodia_platform insert an entry under any tenant_id with no tenant context asserted', function () {
    $id = Str::uuid7()->toString();

    actingAsRole(Rls::PLATFORM_ROLE, null, function () use ($id): void {
        DB::table('activity_log')->insert([
            'id' => $id,
            'tenant_id' => TenantFixture::TENANT_A,
            'log_name' => 'default',
            'description' => 'platform-role cross-tenant entry',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $found = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('activity_log')->where('id', $id)->exists(),
    );

    expect($found)->toBeTrue();
});

it('rejects an update from the owning tenant, activity_log grants no update privilege', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('activity_log')
            ->where('id', $this->entries['tenantA'])
            ->update(['description' => 'tampered']),
    ))->toThrow(QueryException::class, 'permission denied');
});

it('rejects a delete from the owning tenant in request context, nodia_app is never granted delete privilege', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('activity_log')->where('id', $this->entries['tenantA'])->delete(),
    ))->toThrow(QueryException::class, 'permission denied');
});

it('rejects an update from nodia_platform, activity_log grants no update privilege', function () {
    expect(fn () => actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('activity_log')
            ->where('id', $this->entries['tenantA'])
            ->update(['description' => 'tampered']),
    ))->toThrow(QueryException::class, 'permission denied');
});

it('denies nodia_platform a delete with no cutoff set, ordinary platform-role usage removes nothing', function () {
    $deleted = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('activity_log')->where('id', $this->entries['tenantA'])->delete(),
    );

    expect($deleted)->toBe(0);

    $found = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('activity_log')->where('id', $this->entries['tenantA'])->exists(),
    );

    expect($found)->toBeTrue();
});

it('lets nodia_platform delete only rows past the cutoff it explicitly supplies, across every tenant in one pass', function () {
    $cutoff = now()->subDays(400);
    $oldId = Str::uuid7()->toString();
    $youngId = Str::uuid7()->toString();

    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function () use ($oldId, $cutoff): void {
        DB::table('activity_log')->insert([
            'id' => $oldId,
            'tenant_id' => TenantFixture::TENANT_A,
            'log_name' => 'default',
            'description' => 'entry past the retention window',
            'created_at' => $cutoff->clone()->subDay(),
            'updated_at' => $cutoff->clone()->subDay(),
        ]);
    });

    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function () use ($youngId, $cutoff): void {
        DB::table('activity_log')->insert([
            'id' => $youngId,
            'tenant_id' => TenantFixture::TENANT_B,
            'log_name' => 'default',
            'description' => 'entry inside the retention window',
            'created_at' => $cutoff->clone()->addDay(),
            'updated_at' => $cutoff->clone()->addDay(),
        ]);
    });

    $deleted = actingAsRole(Rls::PLATFORM_ROLE, null, function () use ($oldId, $youngId, $cutoff): int {
        DB::selectOne('select set_config(?, ?, true)', ['app.activity_log_prune_cutoff', $cutoff->toIso8601String()]);

        return DB::table('activity_log')->whereIn('id', [$oldId, $youngId])->delete();
    });

    expect($deleted)->toBe(1);

    $survivors = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('activity_log')->whereIn('id', [$oldId, $youngId])->pluck('id'),
    );

    expect($survivors->all())->toBe([$youngId]);
});
