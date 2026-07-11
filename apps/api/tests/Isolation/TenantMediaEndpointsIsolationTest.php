<?php

use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Database\Rls;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Isolation\Support\TenantFixture;
use Tests\Support\StaffTokens;

use function Tests\Isolation\Support\actingAsRole;

/*
 * Stage-05c plan, TDD sequencing Slice 4 ("Contract and isolation as
 * above", i.e. mirroring Slice 2's "Isolation: endpoint-level cross-tenant
 * denial for all three routes"): exercises the real DELETE
 * /v1/media/{media} handler for a tenant logo, proving the media table's
 * tenant_isolation RLS policy makes tenant A's logo genuinely invisible
 * to a tenant B caller, not merely denied by a capability check, even
 * when that caller genuinely holds tenants.manage.
 *
 * POST /v1/tenants/{tenant}/media has no analogous cross-tenant scenario
 * to prove: it runs entirely under the platform posture
 * (PlatformRequestTransaction::asPlatform()), where tenants.manage is by
 * design a cross-tenant capability (system-design 4.3), not one scoped
 * to a caller's own tenant the way events.manage is under tenancy.admin;
 * there is no "foreign tenant" for a platform-scope caller to be denied
 * access to; an unknown tenant id there is already covered as a Feature
 * test (tenant_not_found), not an isolation concern.
 *
 * One issueTenantBLogoToken() call per test, never two: Illuminate\Auth\
 * RequestGuard caches the resolved user on the guard instance itself once
 * resolved, the same hazard tests/Isolation/EventMediaEndpointsIsolationTest.php's
 * own docblock records.
 */

beforeEach(function (): void {
    Storage::fake('media');
    Queue::fake();
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

    app(TenantTransaction::class)->asTenant(
        TenantFixture::TENANT_A,
        fn () => DB::table('media')->where('tenant_id', TenantFixture::TENANT_A)->delete(),
    );

    TenantFixture::clean();
});

/**
 * @return array{id: string}
 */
function attachIsolationLogo(): array
{
    return app(TenantTransaction::class)->asTenant(TenantFixture::TENANT_A, function (): array {
        $tenant = Tenant::query()->whereKey(TenantFixture::TENANT_A)->firstOrFail();
        $media = $tenant->addMedia(UploadedFile::fake()->image('logo.png'))->toMediaCollection('logo');

        return ['id' => $media->id];
    });
}

function issueTenantBLogoToken(): string
{
    $roleId = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => Role::factory()->create([
            'tenant_id' => TenantFixture::TENANT_B,
            'name' => 'Tenant B Branding Role',
            'capabilities' => ['tenants.manage'],
        ])->id,
    );

    $user = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => User::factory()->create());
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

it('renders request.not_found, not missing_capability, for a foreign tenant\'s logo media on DELETE', function () {
    $media = attachIsolationLogo();

    $token = issueTenantBLogoToken();

    test()->deleteJson('/v1/media/'.$media['id'], [], [
        'X-Tenant-Id' => TenantFixture::TENANT_B,
        'Authorization' => 'Bearer '.$token,
    ])
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'request.not_found')
        ->assertJsonPath('status', 404);
});
