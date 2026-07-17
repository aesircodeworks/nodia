<?php

declare(strict_types=1);

use App\Identity\Actions\ResolveTenantAccess;
use App\Identity\Enums\MembershipAccessOutcome;
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
 * Stage-03 plan, Slice 3: "Unit: membership resolution inside the SET
 * LOCAL wrapper". ResolveTenantAccess does none of its own SET LOCAL
 * work (its own docblock explains why), so every case here drives it
 * through TenantTransaction::asTenant() exactly the way
 * ResolveTenantFromHeader does, proving the Action's result is entirely
 * a function of the RLS context already active when it runs rather than
 * anything it sets up itself.
 */

const RTA_TENANT_A = '019797f4-0000-7000-8000-0000000000a1';
const RTA_TENANT_B = '019797f4-0000-7000-8000-0000000000b1';

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::factory()->create(['id' => RTA_TENANT_A]);
        Tenant::factory()->create(['id' => RTA_TENANT_B]);
    });
});

afterEach(function (): void {
    // memberships carries no platform write policy (stage-03 plan, Data
    // model), so each row is cleared under nodia_app scoped to the tenant
    // it belongs to, the sentinel platform tenant included since the
    // platform-scope cases write there.
    foreach ([RTA_TENANT_A, RTA_TENANT_B, config()->string('tenancy.platform_tenant_id')] as $tenantId) {
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

function seedTenantScopeMembership(string $tenantId): string
{
    $user = User::factory()->create();

    app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $user): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'role_id' => Role::factory()->create(['tenant_id' => $tenantId])->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    return $user->id;
}

it('resolves TenantMember for a membership scoped to the target tenant', function () {
    $userId = seedTenantScopeMembership(RTA_TENANT_A);

    $outcome = app(TenantTransaction::class)->asTenant(
        RTA_TENANT_A,
        fn () => app(ResolveTenantAccess::class)->forUser($userId, RTA_TENANT_A),
        $userId,
    );

    expect($outcome)->toBe(MembershipAccessOutcome::TenantMember);
});

it('resolves Denied when the caller has a membership in a different tenant', function () {
    $userId = seedTenantScopeMembership(RTA_TENANT_A);

    $outcome = app(TenantTransaction::class)->asTenant(
        RTA_TENANT_B,
        fn () => app(ResolveTenantAccess::class)->forUser($userId, RTA_TENANT_B),
        $userId,
    );

    expect($outcome)->toBe(MembershipAccessOutcome::Denied);
});

it('resolves Denied for a user with no membership at all', function () {
    $userId = User::factory()->create()->id;

    $outcome = app(TenantTransaction::class)->asTenant(
        RTA_TENANT_A,
        fn () => app(ResolveTenantAccess::class)->forUser($userId, RTA_TENANT_A),
        $userId,
    );

    expect($outcome)->toBe(MembershipAccessOutcome::Denied);
});

it('resolves PlatformMember for a platform-scope membership regardless of the header tenant', function () {
    $userId = User::factory()->create()->id;
    $platformTenantId = config()->string('tenancy.platform_tenant_id');

    app(TenantTransaction::class)->asTenant($platformTenantId, function () use ($userId): void {
        Membership::factory()->platform()->create([
            'user_id' => $userId,
            'role_id' => Role::query()->whereNull('tenant_id')->firstOrFail()->id,
        ]);
    });

    $outcome = app(TenantTransaction::class)->asTenant(
        RTA_TENANT_B,
        fn () => app(ResolveTenantAccess::class)->forUser($userId, RTA_TENANT_B),
        $userId,
    );

    expect($outcome)->toBe(MembershipAccessOutcome::PlatformMember);
});

it('resolves Denied for a platform-scope membership when app.user_id was never set, proving reliance on the SET LOCAL wrapper', function () {
    $userId = User::factory()->create()->id;
    $platformTenantId = config()->string('tenancy.platform_tenant_id');

    app(TenantTransaction::class)->asTenant($platformTenantId, function () use ($userId): void {
        Membership::factory()->platform()->create([
            'user_id' => $userId,
            'role_id' => Role::query()->whereNull('tenant_id')->firstOrFail()->id,
        ]);
    });

    // No $userId argument: app.user_id is never set, so
    // memberships_self_read cannot match this platform-scope row (its own
    // tenant_id is the sentinel, never the target tenant, so
    // memberships_tenant_isolation cannot match it either).
    $outcome = app(TenantTransaction::class)->asTenant(
        RTA_TENANT_B,
        fn () => app(ResolveTenantAccess::class)->forUser($userId, RTA_TENANT_B),
    );

    expect($outcome)->toBe(MembershipAccessOutcome::Denied);
});
