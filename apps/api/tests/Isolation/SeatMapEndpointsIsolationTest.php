<?php

use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;
use Tests\Isolation\Support\SeatMapFixture;
use Tests\Isolation\Support\TenantFixture;
use Tests\Isolation\Support\VenueFixture;
use Tests\Support\StaffTokens;

use function Tests\Isolation\Support\actingAsRole;

/*
 * Stage-05b plan, TDD sequencing Slice 3 ("Isolation: endpoint-level check
 * that a tenant B token with a valid capability gets 404 for tenant A's
 * seat map id"), mirroring RoleEndpointsIsolationTest.php's own precedent:
 * exercises the real GET /v1/seat-maps/{seat_map} and GET
 * /v1/venues/{venue}/seat-maps handlers through the tenancy.admin group,
 * proving the seat_maps and venues tenant_isolation RLS policies make
 * another tenant's rows genuinely invisible to a plain find(), not merely
 * denied by a capability check.
 *
 * The caller needs a tenant-scope membership in tenant B holding
 * events.view, not Tests\Support\PlatformStaff: a platform-scope
 * membership resolves under nodia_platform, whose bypass-RLS posture
 * would make this probe vacuous.
 */

beforeEach(function (): void {
    SeatMapFixture::seed();
});

afterEach(function (): void {
    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
        DB::table('memberships')->where('tenant_id', TenantFixture::TENANT_B)->delete();
    });

    actingAsRole(Rls::PLATFORM_ROLE, null, function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        DB::table('users')->delete();
    });

    SeatMapFixture::clean();
});

it('renders request.not_found, not tenant_access_denied or missing_capability, for a foreign tenant seat map id', function () {
    // events.view so the GET probes reach SeatMapController's
    // seatMapOrFail()/venueOrFail() at all, rather than being turned away
    // earlier by RequireCapability with missing_capability; the
    // assertions below prove the response is 404, never that 403.
    $viewRoleId = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => Role::factory()->create([
            'tenant_id' => TenantFixture::TENANT_B,
            'name' => 'Tenant B Viewer',
            'capabilities' => ['events.view'],
        ])->id,
    );

    $user = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => User::factory()->create());
    $token = StaffTokens::issue($user);

    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function () use ($user, $viewRoleId): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => TenantFixture::TENANT_B,
            'role_id' => $viewRoleId,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    $headers = ['X-Tenant-Id' => TenantFixture::TENANT_B, 'Authorization' => 'Bearer '.$token];

    $assertNotFound = function ($response): void {
        $response->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'request.not_found')
            ->assertJsonPath('status', 404);
    };

    $assertNotFound(test()->getJson('/v1/seat-maps/'.SeatMapFixture::SEAT_MAP_A, $headers));
    $assertNotFound(test()->getJson('/v1/venues/'.VenueFixture::VENUE_A.'/seat-maps', $headers));
});
