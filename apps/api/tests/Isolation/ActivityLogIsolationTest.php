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
 * (stage-03 plan task breakdown item 14): the standard tenant deny
 * matrix for SELECT and INSERT, plus proof that UPDATE and DELETE are
 * denied even for the owning tenant and even for nodia_platform, because
 * neither privilege is ever granted to any application role (system-
 * design 14.2, "log rows are never updated or deleted inside the
 * application"). Assertions target the specific ids ActivityLogFixture::
 * seed() returns rather than a total row count: rows from earlier tests
 * in this file are never deletable and accumulate for the rest of the
 * process (see that fixture's own docblock).
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

it('rejects a delete from the owning tenant, activity_log grants no delete privilege', function () {
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

it('rejects a delete from nodia_platform, activity_log grants no delete privilege', function () {
    expect(fn () => actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('activity_log')->where('id', $this->entries['tenantA'])->delete(),
    ))->toThrow(QueryException::class, 'permission denied');
});
