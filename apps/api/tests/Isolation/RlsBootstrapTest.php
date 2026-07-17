<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\ProbeTable;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

beforeEach(function (): void {
    ProbeTable::drop('rls_bootstrap_probes');
    ProbeTable::createWithHelperPolicies('rls_bootstrap_probes');
});

afterEach(function (): void {
    ProbeTable::drop('rls_bootstrap_probes');
});

function insertRlsProbeRow(string $tenantId, string $label = 'probe'): string
{
    $id = Str::uuid7()->toString();

    actingAsRole(Rls::APP_ROLE, $tenantId, fn () => DB::table('rls_bootstrap_probes')->insert([
        'id' => $id,
        'tenant_id' => $tenantId,
        'label' => $label,
    ]));

    return $id;
}

it('provides both rls roles as nologin nobypassrls group roles', function () {
    $roles = collect(DB::select(
        'select rolname, rolcanlogin, rolbypassrls, rolsuper from pg_roles where rolname in (?, ?) order by rolname',
        [Rls::APP_ROLE, Rls::PLATFORM_ROLE],
    ));

    expect($roles->pluck('rolname')->all())->toBe([Rls::APP_ROLE, Rls::PLATFORM_ROLE])
        ->and($roles->contains(fn ($role) => $role->rolcanlogin || $role->rolbypassrls || $role->rolsuper))->toBeFalse();
});

it('denies by default: nodia_app with no tenant context sees zero rows', function () {
    insertRlsProbeRow(TenantFixture::TENANT_A);

    $rows = actingAsRole(Rls::APP_ROLE, null, fn () => DB::table('rls_bootstrap_probes')->get());

    expect($rows)->toHaveCount(0);
});

it('denies by default: an empty-string tenant setting sees zero rows instead of erroring', function () {
    insertRlsProbeRow(TenantFixture::TENANT_A);

    $rows = actingAsRole(Rls::APP_ROLE, '', fn () => DB::table('rls_bootstrap_probes')->get());

    expect($rows)->toHaveCount(0);
});

it('hides another tenant rows from select under nodia_app', function () {
    insertRlsProbeRow(TenantFixture::TENANT_A);

    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('rls_bootstrap_probes')->get(),
    );

    expect($rows)->toHaveCount(0);
});

it('makes another tenant update affect zero rows under nodia_app', function () {
    $id = insertRlsProbeRow(TenantFixture::TENANT_A);

    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('rls_bootstrap_probes')->where('id', $id)->update(['label' => 'tampered']),
    );

    expect($affected)->toBe(0);

    $label = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('rls_bootstrap_probes')->where('id', $id)->value('label'),
    );

    expect($label)->toBe('probe');
});

it('makes another tenant delete affect zero rows under nodia_app', function () {
    $id = insertRlsProbeRow(TenantFixture::TENANT_A);

    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('rls_bootstrap_probes')->where('id', $id)->delete(),
    );

    expect($affected)->toBe(0);

    $count = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('rls_bootstrap_probes')->count(),
    );

    expect($count)->toBe(1);
});

it('rejects an insert bearing a foreign tenant_id through with check', function () {
    expect(fn () => actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, fn () => DB::table('rls_bootstrap_probes')->insert([
        'id' => Str::uuid7()->toString(),
        'tenant_id' => TenantFixture::TENANT_A,
        'label' => 'forged',
    ])))->toThrow(QueryException::class, 'row-level security');

    $count = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('rls_bootstrap_probes')->count(),
    );

    expect($count)->toBe(0);
});

it('lets nodia_platform read both tenants rows without tenant context', function () {
    insertRlsProbeRow(TenantFixture::TENANT_A);
    insertRlsProbeRow(TenantFixture::TENANT_B);

    $rows = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('rls_bootstrap_probes')->get(),
    );

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('tenant_id')->sort()->values()->all())
        ->toBe([TenantFixture::TENANT_A, TenantFixture::TENANT_B]);
});

it('denies nodia_platform writes when the platform write policy is not opted in', function () {
    $id = insertRlsProbeRow(TenantFixture::TENANT_A);

    expect(fn () => actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('rls_bootstrap_probes')->insert([
        'id' => Str::uuid7()->toString(),
        'tenant_id' => TenantFixture::TENANT_B,
        'label' => 'platform-forged',
    ])))->toThrow(QueryException::class, 'row-level security');

    $affected = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('rls_bootstrap_probes')->where('id', $id)->update(['label' => 'tampered']),
    );

    expect($affected)->toBe(0);
});

describe('opt-in platform write policy', function () {
    beforeEach(function (): void {
        ProbeTable::drop('rls_bootstrap_writable_probes');
        ProbeTable::createWithHelperPolicies('rls_bootstrap_writable_probes', platformWrite: true);
    });

    afterEach(function (): void {
        ProbeTable::drop('rls_bootstrap_writable_probes');
    });

    it('lets nodia_platform insert and update any tenant row', function () {
        $id = Str::uuid7()->toString();

        actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('rls_bootstrap_writable_probes')->insert([
            'id' => $id,
            'tenant_id' => TenantFixture::TENANT_A,
            'label' => 'platform-created',
        ]));

        $affected = actingAsRole(
            Rls::PLATFORM_ROLE,
            null,
            fn () => DB::table('rls_bootstrap_writable_probes')->where('id', $id)->update(['label' => 'platform-updated']),
        );

        expect($affected)->toBe(1);
    });

    it('keeps tenant isolation intact for nodia_app on a platform-writable table', function () {
        actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('rls_bootstrap_writable_probes')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => TenantFixture::TENANT_A,
            'label' => 'platform-created',
        ]));

        $rows = actingAsRole(
            Rls::APP_ROLE,
            TenantFixture::TENANT_B,
            fn () => DB::table('rls_bootstrap_writable_probes')->get(),
        );

        expect($rows)->toHaveCount(0);
    });
});
