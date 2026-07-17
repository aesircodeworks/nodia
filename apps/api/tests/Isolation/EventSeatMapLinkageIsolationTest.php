<?php

use App\EventCatalog\Models\Event;
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
 * Stage-05b plan, TDD sequencing Slice 6 ("Isolation: a seat map id
 * belonging to another tenant behaves as nonexistent (422 or 404 per the
 * validation path, asserted explicitly)"), mirroring
 * SeatMapEndpointsIsolationTest.php's own precedent: exercises the real
 * PATCH /v1/events/{event} handler through the tenancy.admin group,
 * proving App\EventCatalog\Actions\UpdateEvent's SeatMap::query()->find()
 * lookup never sees tenant B's seat map row, so tenant A referencing it
 * gets exactly the same catalog.seat_map_venue_mismatch problem a
 * genuinely nonexistent id gets (App\EventCatalog\Exceptions\
 * SeatMapVenueMismatchException's own docblock decision), never leaking
 * that the row exists for another tenant.
 */

beforeEach(function (): void {
    SeatMapFixture::seed();
});

afterEach(function (): void {
    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
        DB::table('memberships')->where('tenant_id', TenantFixture::TENANT_A)->delete();
        DB::table('events')->where('tenant_id', TenantFixture::TENANT_A)->delete();
    });

    actingAsRole(Rls::PLATFORM_ROLE, null, function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        DB::table('users')->delete();
    });

    SeatMapFixture::clean();
});

it('renders catalog.seat_map_venue_mismatch, never leaking existence, for a foreign tenant seat map id', function () {
    $manageRoleId = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => Role::factory()->create([
            'tenant_id' => TenantFixture::TENANT_A,
            'name' => 'Tenant A Manager',
            'capabilities' => ['events.view', 'events.manage'],
        ])->id,
    );

    $user = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => User::factory()->create());
    $token = StaffTokens::issue($user);

    $event = actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function () use ($user, $manageRoleId): Event {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => TenantFixture::TENANT_A,
            'role_id' => $manageRoleId,
            'scope' => MembershipScope::Tenant,
        ]);

        return Event::factory()->create([
            'tenant_id' => TenantFixture::TENANT_A,
            'is_virtual' => false,
            'venue_id' => VenueFixture::VENUE_A,
            'virtual_event_url' => null,
        ]);
    });

    $headers = ['X-Tenant-Id' => TenantFixture::TENANT_A, 'Authorization' => 'Bearer '.$token];

    test()->patchJson('/v1/events/'.$event->id, ['seat_map_id' => SeatMapFixture::SEAT_MAP_B], $headers)
        ->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'catalog.seat_map_venue_mismatch')
        ->assertJsonPath('status', 422);
});
