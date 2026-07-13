<?php

use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;
use Tests\Isolation\Support\EventFixture;
use Tests\Isolation\Support\ReportEventFinanceFixture;
use Tests\Isolation\Support\TenantFixture;
use Tests\Support\StaffTokens;

use function Tests\Isolation\Support\actingAsRole;

/*
 * Stage-11 plan, TDD sequencing Slice 4: "Same pattern as slice 2 ...
 * isolation" for GET /v1/reports/event-finance. Exercises the real
 * handler through the tenancy.admin group, proving report_event_finance's
 * tenant policy keeps tenant A's row genuinely invisible to a tenant B
 * caller who holds reports.view, not merely denied by a capability check.
 * Runs under the downgraded nodia_isolation connection (tests/Pest.php),
 * so an RLS regression fails loudly rather than leaking through a
 * BYPASSRLS default, mirroring DailySalesEndpointIsolationTest's own
 * posture for the sibling endpoint. reports.view is financially
 * privileged (Capability::isFinanciallyPrivileged), so the bearer's user
 * carries a confirmed MFA session to clear EnforceMfaCompliance.
 */

beforeEach(function (): void {
    ReportEventFinanceFixture::seed();
});

afterEach(function (): void {
    // The extra role, membership, and user issueReportsBToken() creates
    // must go before ReportEventFinanceFixture::clean() reaches
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

    ReportEventFinanceFixture::clean();
});

function issueEventFinanceReportsBToken(): string
{
    $roleId = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => Role::factory()->create([
            'tenant_id' => TenantFixture::TENANT_B,
            'name' => 'Event Finance Reports Tenant B Reader',
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

it('never lists a foreign tenant event finance row', function () {
    $token = issueEventFinanceReportsBToken();

    $response = test()->getJson('/v1/reports/event-finance?per_page=100', [
        'X-Tenant-Id' => TenantFixture::TENANT_B,
        'Authorization' => 'Bearer '.$token,
    ])->assertOk();

    $ids = array_column($response->json('data'), 'event_id');

    expect($ids)->toContain(EventFixture::EVENT_B)
        ->and($ids)->not->toContain(EventFixture::EVENT_A);
});
