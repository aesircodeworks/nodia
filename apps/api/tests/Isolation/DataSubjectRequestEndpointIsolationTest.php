<?php

use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;
use Tests\Isolation\Support\DataSubjectRequestFixture;
use Tests\Isolation\Support\TenantFixture;
use Tests\Support\StaffTokens;

use function Tests\Isolation\Support\actingAsRole;

/*
 * Stage-12 plan, TDD sequencing Slice 2: "Isolation: the signed download
 * URL for tenant A's export is unusable in tenant B's context." Exercises
 * the real GET /v1/data-subject-requests/{data_subject_request} handler
 * through the tenancy.admin group, mirroring
 * tests/Isolation/ExportEndpointIsolationTest.php's own precedent for
 * Stage 11 exports: proving the data_subject_requests tenant policy keeps
 * tenant A's row, and any download_url it would carry, genuinely
 * invisible to a tenant B caller who holds customers.export, not merely
 * denied by a capability check. Neither capability in
 * Capability::isFinanciallyPrivileged, so no MFA confirmation step is
 * needed here, unlike ExportEndpointIsolationTest's own reports.export
 * bearer.
 */

beforeEach(function (): void {
    DataSubjectRequestFixture::seed();
});

afterEach(function (): void {
    // The extra role, membership, and user issueDataSubjectRequestsBToken()
    // creates must go before DataSubjectRequestFixture::clean() reaches
    // TenantFixture::clean(), which deletes the tenants row outright: a
    // dangling roles.tenant_id reference to TENANT_B would otherwise fail
    // that delete's foreign key constraint.
    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
        DB::table('memberships')->where('tenant_id', TenantFixture::TENANT_B)->delete();
    });

    actingAsRole(Rls::PLATFORM_ROLE, null, function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        DB::table('users')->where('email', 'like', '%data-subject-request-endpoint-isolation-test.example')->delete();
    });

    DataSubjectRequestFixture::clean();
});

function issueDataSubjectRequestsBToken(): string
{
    $roleId = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => Role::factory()->create([
            'tenant_id' => TenantFixture::TENANT_B,
            'name' => 'Data Subject Requests Tenant B Reader',
            'capabilities' => ['customers.export'],
        ])->id,
    );

    $user = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => User::factory()->create(['email' => 'dsr-b@data-subject-request-endpoint-isolation-test.example']),
    );
    $token = StaffTokens::issue($user);

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

it('returns 404, never a foreign tenant data subject request, on GET /v1/data-subject-requests/{data_subject_request}', function () {
    $token = issueDataSubjectRequestsBToken();

    // Tenant B's own row is genuinely visible.
    test()->getJson('/v1/data-subject-requests/'.DataSubjectRequestFixture::REQUEST_B, [
        'X-Tenant-Id' => TenantFixture::TENANT_B,
        'Authorization' => 'Bearer '.$token,
    ])->assertOk()->assertJsonPath('id', DataSubjectRequestFixture::REQUEST_B);

    // Tenant A's row, fetched by a tenant B caller, is indistinguishable
    // from an unknown id: no download_url is ever computed or leaked for
    // it, since the row is never resolved at all under tenant B's
    // RLS-scoped query.
    test()->getJson('/v1/data-subject-requests/'.DataSubjectRequestFixture::REQUEST_A, [
        'X-Tenant-Id' => TenantFixture::TENANT_B,
        'Authorization' => 'Bearer '.$token,
    ])->assertStatus(404)->assertJsonPath('code', 'request.not_found');
});
