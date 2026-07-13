<?php

use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;
use Tests\Isolation\Support\ExportFixture;
use Tests\Isolation\Support\TenantFixture;
use Tests\Support\StaffTokens;

use function Tests\Isolation\Support\actingAsRole;

/*
 * Stage-11 plan, TDD sequencing Slice 8: "Isolation: exports cross-tenant
 * denial, including that tenant A cannot fetch or download tenant B's
 * export by ID (404)." Exercises the real GET /v1/exports/{export} and
 * GET /v1/exports/{export}/download handlers through the tenancy.admin
 * group, proving exports' tenant policy keeps tenant A's row genuinely
 * invisible to a tenant B caller who holds reports.export, not merely
 * denied by a capability check. Runs under the downgraded
 * nodia_isolation connection (tests/Pest.php), so an RLS regression
 * fails loudly, mirroring DailySalesEndpointIsolationTest's own posture.
 * reports.export is financially privileged
 * (Capability::isFinanciallyPrivileged), so the bearer's user carries a
 * confirmed MFA session to clear EnforceMfaCompliance. Both cases render
 * 404, not 409: the standard not-found path fires before the status
 * check ever runs, since the row is never resolved at all under tenant
 * B's RLS-scoped query.
 */

beforeEach(function (): void {
    ExportFixture::seed();
});

afterEach(function (): void {
    // The extra role, membership, and user issueExportsBToken() creates
    // must go before ExportFixture::clean() reaches TenantFixture::clean(),
    // which deletes the tenants row outright: a dangling roles.tenant_id
    // reference to TENANT_B would otherwise fail that delete's foreign
    // key constraint.
    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
        DB::table('memberships')->where('tenant_id', TenantFixture::TENANT_B)->delete();
    });

    actingAsRole(Rls::PLATFORM_ROLE, null, function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        DB::table('users')->where('email', 'like', '%export-endpoint-isolation-test.example')->delete();
    });

    ExportFixture::clean();
});

function issueExportsBToken(): string
{
    $roleId = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => Role::factory()->create([
            'tenant_id' => TenantFixture::TENANT_B,
            'name' => 'Exports Tenant B Reader',
            'capabilities' => ['reports.export'],
        ])->id,
    );

    $user = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => User::factory()->create(['email' => 'export-b@export-endpoint-isolation-test.example']),
    );
    // Issue before confirming MFA: the staff token endpoint rejects a
    // credential login once the account has MFA enabled. reports.export
    // is financially privileged, so the resolved session still needs a
    // confirmed MFA session to clear EnforceMfaCompliance.
    $token = StaffTokens::issue($user);
    actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => $user->forceFill(['mfa_enabled' => true, 'mfa_confirmed_at' => now()])->save(),
    );

    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function () use ($user, $roleId): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => TenantFixture::TENANT_B,
            'role_id' => $roleId,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    return $token;
}

it('returns 404, never a foreign tenant export, on GET /v1/exports/{export}', function () {
    $token = issueExportsBToken();

    // Tenant B's own row is genuinely visible.
    test()->getJson('/v1/exports/'.ExportFixture::ROW_B, [
        'X-Tenant-Id' => TenantFixture::TENANT_B,
        'Authorization' => 'Bearer '.$token,
    ])->assertOk()->assertJsonPath('id', ExportFixture::ROW_B);

    // Tenant A's row, fetched by a tenant B caller, is indistinguishable
    // from an unknown id.
    test()->getJson('/v1/exports/'.ExportFixture::ROW_A, [
        'X-Tenant-Id' => TenantFixture::TENANT_B,
        'Authorization' => 'Bearer '.$token,
    ])->assertStatus(404)->assertJsonPath('code', 'request.not_found');
});

it('returns 404, never 409, when downloading a foreign tenant export', function () {
    $token = issueExportsBToken();

    // Tenant A's row is pending in the fixture (ExportFactory's own
    // default), which would render 409 export_not_ready for tenant A
    // itself; a tenant B caller must see 404 instead, proving the
    // not-found path fires before the status is ever inspected because
    // the row was never resolved under tenant B's RLS-scoped query.
    test()->getJson('/v1/exports/'.ExportFixture::ROW_A.'/download', [
        'X-Tenant-Id' => TenantFixture::TENANT_B,
        'Authorization' => 'Bearer '.$token,
    ])->assertStatus(404)->assertJsonPath('code', 'request.not_found');
});
