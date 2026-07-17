<?php

use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;
use Tests\Isolation\Support\EventFixture;
use Tests\Isolation\Support\TenantFixture;
use Tests\Support\StaffTokens;

use function Tests\Isolation\Support\actingAsRole;

/*
 * Stage-09 plan, Slice 3: a staff token scoped to tenant A requesting
 * tenant B's event manifest sees 404, proving events' tenant_isolation
 * policy makes the foreign event genuinely invisible under RLS, not
 * merely denied by a capability check. Runs under the downgraded
 * nodia_isolation connection, mirroring
 * tests/Isolation/RefundReadEndpointsIsolationTest.php's own posture.
 */

beforeEach(function (): void {
    EventFixture::seed();
});

afterEach(function (): void {
    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
        DB::table('memberships')->where('tenant_id', TenantFixture::TENANT_A)->delete();
    });

    actingAsRole(Rls::PLATFORM_ROLE, null, function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        DB::table('users')->delete();
    });

    EventFixture::clean();
});

function issueTenantAManifestToken(): string
{
    $roleId = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => Role::factory()->create([
            'tenant_id' => TenantFixture::TENANT_A,
            'name' => 'Tenant A Check-in Manager',
            'capabilities' => ['checkin.manage'],
        ])->id,
    );

    $user = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => User::factory()->create());
    $token = StaffTokens::issue($user);
    $user->forceFill(['mfa_enabled' => true, 'mfa_confirmed_at' => now()])->save();

    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function () use ($user, $roleId): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => TenantFixture::TENANT_A,
            'role_id' => $roleId,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    return $token;
}

it('renders event_not_found, not the manifest, for a foreign tenant event id', function (): void {
    $token = issueTenantAManifestToken();

    test()->getJson('/v1/events/'.EventFixture::EVENT_B.'/check-in-manifest', [
        'X-Tenant-Id' => TenantFixture::TENANT_A,
        'Authorization' => 'Bearer '.$token,
    ])
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'event_not_found')
        ->assertJsonPath('status', 404);
});

it('returns 200 for the tenant\'s own event', function (): void {
    $token = issueTenantAManifestToken();

    test()->getJson('/v1/events/'.EventFixture::EVENT_A.'/check-in-manifest', [
        'X-Tenant-Id' => TenantFixture::TENANT_A,
        'Authorization' => 'Bearer '.$token,
    ])->assertOk();
});
