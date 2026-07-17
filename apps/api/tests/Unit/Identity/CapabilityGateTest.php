<?php

declare(strict_types=1);

use App\Identity\Authorization\CapabilityGate;
use App\Identity\Capability;
use App\Identity\Enums\MembershipScope;
use App\Identity\Exceptions\MissingCapabilityException;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Problems\ErrorCode;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-03 plan, Slice 4 (task breakdown item 6): "Unit: capability set
 * evaluation; Gate resolution of the acting membership from user plus
 * asserted tenant." Drives CapabilityGate directly (no HTTP), setting the
 * staff guard's user and the tenant transaction the same way the real
 * request pipeline (auth:staff, then ResolveTenantFromHeader) does, so the
 * assertions exercise the real RLS-backed lookup, not a stub.
 */

const CG_TENANT = '019797f6-0000-7000-8000-0000000000a1';

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::factory()->create(['id' => CG_TENANT]);
    });
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant(CG_TENANT, function (): void {
        DB::table('memberships')->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    User::query()->delete();

    Auth::forgetGuards();
});

function memberWithCapabilities(array $capabilities): User
{
    $user = User::factory()->create();

    app(TenantTransaction::class)->asTenant(CG_TENANT, function () use ($user, $capabilities): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => CG_TENANT,
            'role_id' => Role::factory()->create([
                'tenant_id' => CG_TENANT,
                'capabilities' => array_map(fn (Capability $capability): string => $capability->value, $capabilities),
            ])->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    return $user;
}

it('allows a capability the acting membership role holds', function () {
    $user = memberWithCapabilities([Capability::EventsView, Capability::OrdersView]);

    app(TenantTransaction::class)->asTenant(CG_TENANT, function () use ($user): void {
        Auth::guard('staff')->setUser($user);

        expect(app(CapabilityGate::class)->allowsFor($user, Capability::EventsView))->toBeTrue();

        app(CapabilityGate::class)->authorize(Capability::EventsView);
    }, $user->id);
});

it('denies a capability the acting membership role does not hold', function () {
    $user = memberWithCapabilities([Capability::EventsView]);

    app(TenantTransaction::class)->asTenant(CG_TENANT, function () use ($user): void {
        Auth::guard('staff')->setUser($user);

        expect(app(CapabilityGate::class)->allowsFor($user, Capability::OrdersRefund))->toBeFalse();

        app(CapabilityGate::class)->authorize(Capability::OrdersRefund);
    }, $user->id);
})->throws(MissingCapabilityException::class);

it('passes authorizeAny when the acting membership holds the second listed capability, not the first', function () {
    $user = memberWithCapabilities([Capability::CustomersExport]);

    app(TenantTransaction::class)->asTenant(CG_TENANT, function () use ($user): void {
        Auth::guard('staff')->setUser($user);

        app(CapabilityGate::class)->authorizeAny(Capability::CustomersErase, Capability::CustomersExport);
    }, $user->id);

    expect(true)->toBeTrue();
});

it('denies authorizeAny naming the first listed capability when the acting membership holds neither', function () {
    $user = memberWithCapabilities([Capability::EventsView]);

    app(TenantTransaction::class)->asTenant(CG_TENANT, function () use ($user): void {
        Auth::guard('staff')->setUser($user);

        app(CapabilityGate::class)->authorizeAny(Capability::CustomersErase, Capability::CustomersExport);
    }, $user->id);
})->throws(MissingCapabilityException::class, 'customers.erase');

it('carries the missing_capability error code', function () {
    $exception = MissingCapabilityException::for(Capability::RolesManage);

    expect($exception->errorCode())->toBe(ErrorCode::MissingCapability)
        ->and($exception->getMessage())->toContain('roles.manage');
});

it('denies every capability for a user with no membership in the asserted tenant', function () {
    $user = User::factory()->create();

    app(TenantTransaction::class)->asTenant(CG_TENANT, function () use ($user): void {
        Auth::guard('staff')->setUser($user);

        expect(app(CapabilityGate::class)->allowsFor($user, Capability::EventsView))->toBeFalse();
    }, $user->id);
});

it('denies when no tenant context is active at all', function () {
    $user = memberWithCapabilities([Capability::EventsView]);

    Auth::guard('staff')->setUser($user);

    expect(app(CapabilityGate::class)->allowsFor($user, Capability::EventsView))->toBeFalse();
});

it('denies via the Gate facade for a non-capability ability, leaving normal resolution to deny it', function () {
    CapabilityGate::register();

    $user = memberWithCapabilities(Capability::cases());

    app(TenantTransaction::class)->asTenant(CG_TENANT, function () use ($user): void {
        Auth::guard('staff')->setUser($user);

        expect(Gate::forUser($user)->allows('not-a-capability'))->toBeFalse();
    }, $user->id);
});

it('routes every real capability check for an authenticated user through the Gate facade', function () {
    CapabilityGate::register();

    $user = memberWithCapabilities([Capability::CheckinScan]);

    app(TenantTransaction::class)->asTenant(CG_TENANT, function () use ($user): void {
        Auth::guard('staff')->setUser($user);

        expect(Gate::forUser($user)->allows(Capability::CheckinScan->value))->toBeTrue()
            ->and(Gate::forUser($user)->allows(Capability::TenantsManage->value))->toBeFalse();
    }, $user->id);
});
