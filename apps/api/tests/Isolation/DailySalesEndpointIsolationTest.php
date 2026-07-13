<?php

use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;
use Tests\Isolation\Support\EventFixture;
use Tests\Isolation\Support\ReportDailySalesFixture;
use Tests\Isolation\Support\TenantFixture;
use Tests\Support\StaffTokens;

use function Tests\Isolation\Support\actingAsRole;

/*
 * Stage-11 plan, TDD sequencing Slice 2: "Isolation: the endpoint returns
 * only the acting tenant's rows with two tenants seeded." Exercises the
 * real GET /v1/reports/daily-sales handler through the tenancy.admin
 * group, proving report_daily_sales's tenant policy keeps tenant A's row
 * genuinely invisible to a tenant B caller who holds reports.view, not
 * merely denied by a capability check. Runs under the downgraded
 * nodia_isolation connection (tests/Pest.php), so an RLS regression fails
 * loudly rather than leaking through a BYPASSRLS default, mirroring
 * LedgerReadEndpointsIsolationTest's own posture for the analogous
 * ledger-entries read. reports.view is financially privileged
 * (Capability::isFinanciallyPrivileged), so the bearer's user carries a
 * confirmed MFA session to clear EnforceMfaCompliance.
 */

beforeEach(function (): void {
    ReportDailySalesFixture::seed();
});

afterEach(function (): void {
    // The extra role, membership, and user issueReportsBToken() creates
    // must go before ReportDailySalesFixture::clean() reaches
    // TenantFixture::clean(), which deletes the tenants row outright: a
    // dangling roles.tenant_id reference to TENANT_B would otherwise fail
    // that delete's foreign key constraint.
    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
        DB::table('memberships')->where('tenant_id', TenantFixture::TENANT_B)->delete();
    });

    actingAsRole(Rls::PLATFORM_ROLE, null, function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        DB::table('users')->delete();
    });

    ReportDailySalesFixture::clean();
});

function issueReportsBToken(): string
{
    $roleId = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => Role::factory()->create([
            'tenant_id' => TenantFixture::TENANT_B,
            'name' => 'Reports Tenant B Reader',
            'capabilities' => ['reports.view'],
        ])->id,
    );

    $user = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => User::factory()->create());
    // Issue before confirming MFA: the staff token endpoint rejects a
    // credential login once the account has MFA enabled. reports.view is
    // financially privileged, so the resolved session still needs a
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

it('never lists a foreign tenant daily sales row', function () {
    $token = issueReportsBToken();

    $response = test()->getJson('/v1/reports/daily-sales?per_page=100', [
        'X-Tenant-Id' => TenantFixture::TENANT_B,
        'Authorization' => 'Bearer '.$token,
    ])->assertOk();

    $ids = array_column($response->json('data'), 'event_id');

    expect($ids)->toContain(EventFixture::EVENT_B)
        ->and($ids)->not->toContain(EventFixture::EVENT_A);
});
