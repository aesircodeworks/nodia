<?php

declare(strict_types=1);

use App\Identity\Actions\ResolveActingMembership;
use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-03 plan, Slice 4 (task breakdown item 6): "Unit: ... Gate
 * resolution of the acting membership from user plus asserted tenant."
 * ResolveActingMembership is the primitive CapabilityGate resolves the
 * acting role from; ResolveTenantAccess (Slice 3) now delegates to it
 * rather than duplicating the same lookup. Every case drives it through
 * TenantTransaction::asTenant() exactly the way the tenant resolution
 * middleware does, proving the result is entirely a function of the RLS
 * context already active when it runs.
 */

const RAM_TENANT_A = '019797f5-0000-7000-8000-0000000000a1';
const RAM_TENANT_B = '019797f5-0000-7000-8000-0000000000b1';

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::factory()->create(['id' => RAM_TENANT_A]);
        Tenant::factory()->create(['id' => RAM_TENANT_B]);
    });
});

afterEach(function (): void {
    foreach ([RAM_TENANT_A, RAM_TENANT_B, config()->string('tenancy.platform_tenant_id')] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function (): void {
            DB::table('memberships')->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    User::query()->delete();
});

it('resolves the tenant-scope membership with its role loaded', function () {
    $user = User::factory()->create();

    app(TenantTransaction::class)->asTenant(RAM_TENANT_A, function () use ($user): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => RAM_TENANT_A,
            'role_id' => Role::factory()->create(['tenant_id' => RAM_TENANT_A, 'capabilities' => ['events.view']])->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    $membership = app(TenantTransaction::class)->asTenant(
        RAM_TENANT_A,
        fn () => app(ResolveActingMembership::class)->forUser($user->id, RAM_TENANT_A),
        $user->id,
    );

    expect($membership)->not->toBeNull()
        ->and($membership->scope)->toBe(MembershipScope::Tenant)
        ->and($membership->role->capabilities)->toBe(['events.view']);
});

it('falls back to the platform-scope membership when no tenant-scope row exists', function () {
    $user = User::factory()->create();
    $platformTenantId = config()->string('tenancy.platform_tenant_id');

    app(TenantTransaction::class)->asTenant($platformTenantId, function () use ($user): void {
        Membership::factory()->platform()->create([
            'user_id' => $user->id,
            'role_id' => Role::query()->whereNull('tenant_id')->firstOrFail()->id,
        ]);
    });

    $membership = app(TenantTransaction::class)->asTenant(
        RAM_TENANT_B,
        fn () => app(ResolveActingMembership::class)->forUser($user->id, RAM_TENANT_B),
        $user->id,
    );

    expect($membership)->not->toBeNull()
        ->and($membership->scope)->toBe(MembershipScope::Platform);
});

it('prefers the tenant-scope membership over a platform-scope membership held by the same user', function () {
    $user = User::factory()->create();
    $platformTenantId = config()->string('tenancy.platform_tenant_id');

    app(TenantTransaction::class)->asTenant($platformTenantId, function () use ($user): void {
        Membership::factory()->platform()->create([
            'user_id' => $user->id,
            'role_id' => Role::query()->whereNull('tenant_id')->firstOrFail()->id,
        ]);
    });

    app(TenantTransaction::class)->asTenant(RAM_TENANT_A, function () use ($user): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => RAM_TENANT_A,
            'role_id' => Role::factory()->create(['tenant_id' => RAM_TENANT_A])->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    $membership = app(TenantTransaction::class)->asTenant(
        RAM_TENANT_A,
        fn () => app(ResolveActingMembership::class)->forUser($user->id, RAM_TENANT_A),
        $user->id,
    );

    expect($membership->scope)->toBe(MembershipScope::Tenant)
        ->and($membership->tenant_id)->toBe(RAM_TENANT_A);
});

it('returns null for a user with no membership at all', function () {
    $user = User::factory()->create();

    $membership = app(TenantTransaction::class)->asTenant(
        RAM_TENANT_A,
        fn () => app(ResolveActingMembership::class)->forUser($user->id, RAM_TENANT_A),
        $user->id,
    );

    expect($membership)->toBeNull();
});

it('returns null when the caller only holds a membership in a different tenant', function () {
    $user = User::factory()->create();

    app(TenantTransaction::class)->asTenant(RAM_TENANT_A, function () use ($user): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => RAM_TENANT_A,
            'role_id' => Role::factory()->create(['tenant_id' => RAM_TENANT_A])->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    $membership = app(TenantTransaction::class)->asTenant(
        RAM_TENANT_B,
        fn () => app(ResolveActingMembership::class)->forUser($user->id, RAM_TENANT_B),
        $user->id,
    );

    expect($membership)->toBeNull();
});

it('returns null for a platform-scope membership when app.user_id was never set', function () {
    $user = User::factory()->create();
    $platformTenantId = config()->string('tenancy.platform_tenant_id');

    app(TenantTransaction::class)->asTenant($platformTenantId, function () use ($user): void {
        Membership::factory()->platform()->create([
            'user_id' => $user->id,
            'role_id' => Role::query()->whereNull('tenant_id')->firstOrFail()->id,
        ]);
    });

    $membership = app(TenantTransaction::class)->asTenant(
        RAM_TENANT_B,
        fn () => app(ResolveActingMembership::class)->forUser($user->id, RAM_TENANT_B),
    );

    expect($membership)->toBeNull();
});
