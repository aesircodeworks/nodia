<?php

use App\Identity\Models\Role;
use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\IdentityFixture;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * roles carries the sanctioned nullable-tenant_id exception: a NULL
 * tenant_id template role must be readable from every tenant context and
 * mutable from none of them; a tenant's own custom role is isolated the
 * standard way. The five templates the migration seeds are used directly
 * rather than creating a throwaway one, proving the actual production
 * rows the platform maintains.
 */

beforeEach(fn () => IdentityFixture::seed());

afterEach(fn () => IdentityFixture::clean());

it('shows a tenant only its own custom role, never the other tenant custom role', function () {
    $names = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('roles')->whereNotNull('tenant_id')->pluck('name'),
    );

    expect($names->all())->toBe(['Custom A']);
});

it('shows every template role from both tenant contexts', function (string $tenantId) {
    $templateNames = actingAsRole(
        Rls::APP_ROLE,
        $tenantId,
        fn () => DB::table('roles')->whereNull('tenant_id')->pluck('name')->sort()->values(),
    );

    expect($templateNames->all())->toBe(['Box Office', 'Check-in Agent', 'Event Manager', 'Finance', 'Owner']);
})->with([
    'tenant A' => [TenantFixture::TENANT_A],
    'tenant B' => [TenantFixture::TENANT_B],
]);

it('rejects a nodia_app update of a template role from either tenant context', function (string $tenantId) {
    $owner = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => Role::query()->whereNull('tenant_id')->where('name', 'Owner')->firstOrFail());

    $affected = actingAsRole(
        Rls::APP_ROLE,
        $tenantId,
        fn () => DB::table('roles')->where('id', $owner->id)->update(['name' => 'Hijacked']),
    );

    expect($affected)->toBe(0);

    $name = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('roles')->where('id', $owner->id)->value('name'));

    expect($name)->toBe('Owner');
})->with([
    'tenant A' => [TenantFixture::TENANT_A],
    'tenant B' => [TenantFixture::TENANT_B],
]);

it('rejects a nodia_app delete of a template role from either tenant context', function (string $tenantId) {
    $owner = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => Role::query()->whereNull('tenant_id')->where('name', 'Owner')->firstOrFail());

    $affected = actingAsRole(
        Rls::APP_ROLE,
        $tenantId,
        fn () => DB::table('roles')->where('id', $owner->id)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('roles')->where('id', $owner->id)->exists());

    expect($exists)->toBeTrue();
})->with([
    'tenant A' => [TenantFixture::TENANT_A],
    'tenant B' => [TenantFixture::TENANT_B],
]);

it('rejects a nodia_app insert of a new template role from either tenant context', function (string $tenantId) {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        $tenantId,
        fn () => DB::table('roles')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => null,
            'name' => 'Forged Template',
            'capabilities' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]),
    ))->toThrow(QueryException::class, 'row-level security');

    $exists = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('roles')->where('name', 'Forged Template')->exists());

    expect($exists)->toBeFalse();
})->with([
    'tenant A' => [TenantFixture::TENANT_A],
    'tenant B' => [TenantFixture::TENANT_B],
]);

it('makes a cross-tenant update of a custom role affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('roles')->where('id', IdentityFixture::ROLE_B)->update(['name' => 'Hijacked']),
    );

    expect($affected)->toBe(0);

    $name = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('roles')->where('id', IdentityFixture::ROLE_B)->value('name'));

    expect($name)->toBe('Custom B');
});

it('makes a cross-tenant delete of a custom role affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('roles')->where('id', IdentityFixture::ROLE_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('roles')->where('id', IdentityFixture::ROLE_B)->exists());

    expect($exists)->toBeTrue();
});

it('rejects a cross-tenant insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('roles')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => TenantFixture::TENANT_B,
            'name' => 'Forged Custom',
            'capabilities' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]),
    ))->toThrow(QueryException::class, 'row-level security');

    $exists = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('roles')->where('name', 'Forged Custom')->exists());

    expect($exists)->toBeFalse();
});

it('lets nodia_platform read every role, templates and custom roles alike, with no tenant context', function () {
    $names = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('roles')->pluck('name'));

    expect($names->all())->toContain('Owner', 'Event Manager', 'Box Office', 'Finance', 'Check-in Agent', 'Custom A', 'Custom B');
});

it('lets nodia_platform write a template role and nothing else can', function () {
    $updated = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('roles')->whereNull('tenant_id')->where('name', 'Finance')->update(['capabilities' => '["events.view"]']),
    );

    expect($updated)->toBe(1);

    $capabilities = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('roles')->where('name', 'Finance')->value('capabilities'));

    expect(json_decode((string) $capabilities))->toBe(['events.view']);
});

it('lets nodia_platform write a tenant custom role too', function () {
    $updated = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('roles')->where('id', IdentityFixture::ROLE_A)->update(['name' => 'Renamed by platform']),
    );

    expect($updated)->toBe(1);
});
