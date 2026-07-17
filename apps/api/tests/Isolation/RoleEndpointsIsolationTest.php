<?php

use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;
use Tests\Isolation\Support\TenantFixture;
use Tests\Support\StaffTokens;

use function Tests\Isolation\Support\actingAsRole;

/*
 * Stage-03 plan, Slice 4 isolation denial probes (task breakdown item 8):
 * a foreign-tenant role ID in the URL must render 404 request.not_found,
 * never 403, so the response never discloses whether the role exists.
 * Exercises the real GET/PATCH/DELETE /v1/roles/{role} handlers through
 * the tenancy.admin group, proving roles_template_or_tenant_read RLS
 * (task-04) makes another tenant's custom role genuinely invisible to a
 * plain find(), not merely denied.
 *
 * The caller needs a tenant-scope membership in the asserted tenant, not
 * Tests\Support\PlatformStaff: a platform-scope membership resolves under
 * nodia_platform, whose roles_platform_write policy sees every tenant's
 * roles regardless of the asserted tenant, which would make this probe
 * vacuous.
 */

beforeEach(function (): void {
    TenantFixture::seed();
});

afterEach(function (): void {
    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
        DB::table('memberships')->where('tenant_id', TenantFixture::TENANT_B)->delete();
    });

    actingAsRole(Rls::PLATFORM_ROLE, null, function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        DB::table('users')->delete();
    });

    TenantFixture::clean();
});

it('renders request.not_found, not tenant_access_denied or missing_capability, for a foreign tenant role id', function () {
    $foreignRoleId = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => Role::factory()->create(['tenant_id' => TenantFixture::TENANT_A, 'name' => 'Tenant A Only'])->id,
    );

    // roles.manage so the PATCH/DELETE probes reach RoleController's
    // roleOrFail() at all, rather than being turned away earlier by
    // RequireCapability with missing_capability; the assertion below
    // proves the response is 404, never that 403.
    $manageRoleId = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => Role::factory()->create([
            'tenant_id' => TenantFixture::TENANT_B,
            'name' => 'Tenant B Manager',
            'capabilities' => ['roles.manage'],
        ])->id,
    );

    $user = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => User::factory()->create());
    $token = StaffTokens::issue($user);

    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function () use ($user, $manageRoleId): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => TenantFixture::TENANT_B,
            'role_id' => $manageRoleId,
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

    $assertNotFound(test()->getJson('/v1/roles/'.$foreignRoleId, $headers));
    $assertNotFound(test()->patchJson('/v1/roles/'.$foreignRoleId, ['name' => 'Hijacked'], $headers));
    $assertNotFound(test()->deleteJson('/v1/roles/'.$foreignRoleId, [], $headers));
});
