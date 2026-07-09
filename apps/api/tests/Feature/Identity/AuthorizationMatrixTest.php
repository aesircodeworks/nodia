<?php

declare(strict_types=1);

use App\Identity\Actions\SeedTemplateRoles;
use App\Identity\Authorization\CapabilityGate;
use App\Identity\Capability;
use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;

/*
 * Stage-03 plan, Slice 4 / task breakdown item 6: the authorization matrix
 * as a data-driven feature test enumerating every Capability case, for
 * each seeded template role plus a custom role, in the member's tenant and
 * a foreign tenant (system-design 5.3, ADR 012). The dataset is built from
 * Capability::cases() and SeedTemplateRoles::templates() directly, so it
 * stays exhaustive as later stages extend the capability registry, the
 * single source Stage 12's completeness check extends.
 *
 * The probe route exercises the real request pipeline (auth:staff,
 * ResolveTenantFromHeader's membership validation, then CapabilityGate)
 * rather than calling the Gate directly, so a 403 missing_capability is
 * proven end to end through the exception handler, the same way
 * TenantMembershipResolutionTest proves the Slice 3 resolution matrix
 * against an ad hoc probe route registered per test rather than a shipped
 * endpoint, since no real capability-gated endpoint exists before task
 * breakdown items 7 through 9 attach one.
 *
 * The foreign-tenant leg holds a second, fixed membership under a role
 * that carries only Capability::EventsView: expecting foreign-tenant
 * outcomes to track that fixed role regardless of the home role under
 * test proves capability evaluation is scoped to the asserted tenant's own
 * membership, never leaked from a membership held elsewhere (system-design
 * 5.3: "never role names").
 */

const CUSTOM_ROLE_NAME = 'Tenant Custom Probe';
const FOREIGN_ROLE_CAPABILITIES = [Capability::EventsView];

/**
 * @return list<Capability>
 */
function customRoleCapabilities(): array
{
    return [Capability::TenantsManage, Capability::EventsView, Capability::OrdersView];
}

/**
 * @return array<string, list<Capability>>
 */
function matrixRoleCapabilitySets(): array
{
    return [...SeedTemplateRoles::templates(), CUSTOM_ROLE_NAME => customRoleCapabilities()];
}

/**
 * @return array<string, array{0: Capability, 1: string}>
 */
function capabilityMatrixCases(): array
{
    $cases = [];

    foreach (Capability::cases() as $capability) {
        foreach (array_keys(matrixRoleCapabilitySets()) as $roleName) {
            $cases[sprintf('%s / %s', $capability->value, $roleName)] = [$capability, $roleName];
        }
    }

    return $cases;
}

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    Route::middleware('tenancy.admin')->prefix('v1')->get('/__probe/capability/{capability}', function (string $capability) {
        app(CapabilityGate::class)->authorize(Capability::from($capability));

        return response()->noContent();
    });
});

afterEach(function (): void {
    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->pluck('id')->all(),
    );

    foreach ([...$tenantIds, config()->string('tenancy.platform_tenant_id')] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('memberships')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    User::query()->delete();
});

/**
 * @param  list<Capability>  $capabilities
 */
function createRoleInTenant(string $tenantId, string $name, array $capabilities): string
{
    return app(TenantTransaction::class)->asTenant($tenantId, fn () => Role::factory()->create([
        'tenant_id' => $tenantId,
        'name' => $name,
        'capabilities' => array_map(fn (Capability $capability): string => $capability->value, $capabilities),
    ])->id);
}

function assignMembership(string $userId, string $tenantId, string $roleId): void
{
    app(TenantTransaction::class)->asTenant($tenantId, function () use ($userId, $tenantId, $roleId): void {
        Membership::factory()->create([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'role_id' => $roleId,
            'scope' => MembershipScope::Tenant,
        ]);
    });
}

it('evaluates capability plus tenant context for every capability against every seeded role', function (Capability $capability, string $roleName) {
    $homeCapabilities = matrixRoleCapabilitySets()[$roleName];

    $homeTenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $foreignTenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $user = User::factory()->create();
    $token = StaffTokens::issue($user);

    $homeRoleId = $roleName === CUSTOM_ROLE_NAME
        ? createRoleInTenant($homeTenantId, CUSTOM_ROLE_NAME, $homeCapabilities)
        : Role::query()->whereNull('tenant_id')->where('name', $roleName)->firstOrFail()->id;

    assignMembership($user->id, $homeTenantId, $homeRoleId);

    $foreignRoleId = createRoleInTenant($foreignTenantId, 'Cross-Tenant Probe', FOREIGN_ROLE_CAPABILITIES);
    assignMembership($user->id, $foreignTenantId, $foreignRoleId);

    $probe = fn (string $tenantId) => test()->getJson('/v1/__probe/capability/'.$capability->value, [
        'X-Tenant-Id' => $tenantId,
        'Authorization' => 'Bearer '.$token,
    ]);

    $assertOutcome = function ($response, bool $allowed): void {
        if ($allowed) {
            $response->assertNoContent();

            return;
        }

        $response->assertStatus(403)
            ->assertMatchesProblemSchema()
            ->assertJsonPath('code', 'missing_capability')
            ->assertJsonPath('status', 403);
    };

    $assertOutcome($probe($homeTenantId), in_array($capability, $homeCapabilities, true));
    $assertOutcome($probe($foreignTenantId), in_array($capability, FOREIGN_ROLE_CAPABILITIES, true));
})->with(capabilityMatrixCases());
