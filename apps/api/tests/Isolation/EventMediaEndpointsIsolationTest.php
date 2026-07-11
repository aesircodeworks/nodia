<?php

use App\EventCatalog\Models\Event;
use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Database\Rls;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Isolation\Support\EventFixture;
use Tests\Isolation\Support\TenantFixture;
use Tests\Support\StaffTokens;

use function Tests\Isolation\Support\actingAsRole;

/*
 * Stage-05c plan, TDD sequencing Slice 2 ("Isolation: endpoint-level
 * cross-tenant denial for all three routes"), mirroring
 * SeatMapEndpointsIsolationTest.php's own precedent: exercises the real
 * POST/GET /v1/events/{event}/media and DELETE /v1/media/{media} handlers
 * through the tenancy.admin group, proving the events and media
 * tenant_isolation RLS policies make tenant A's rows genuinely invisible
 * to tenant B's own valid-capability caller, not merely denied by a
 * capability check.
 *
 * The caller needs a tenant-scope membership in tenant B holding the
 * relevant capability, not Tests\Support\PlatformStaff: a platform-scope
 * membership resolves under nodia_platform, whose bypass-RLS posture
 * would make this probe vacuous.
 *
 * One issueTenantBToken() call per test, never two: Illuminate\Auth\
 * RequestGuard caches the resolved user on the guard instance itself
 * once resolved ("If we've already retrieved the user for the current
 * request we can just return it back immediately"), and that guard
 * instance is cached on the shared AuthManager for the whole test
 * method, not rebuilt per Illuminate\Foundation\Testing\TestCase::call().
 * A second bearer token exchanged mid-test would silently keep
 * authenticating as the first token's user instead of failing loudly,
 * which is why SeatMapEndpointsIsolationTest's own precedent never mixes
 * two tokens in one test either.
 */

beforeEach(function (): void {
    Storage::fake('media');
    EventFixture::seed();
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

    EventFixture::clean();
});

/**
 * @return array{id: string}
 */
function attachIsolationCover(): array
{
    return app(TenantTransaction::class)->asTenant(TenantFixture::TENANT_A, function (): array {
        $event = Event::query()->whereKey(EventFixture::EVENT_A)->firstOrFail();
        $media = $event->addMedia(UploadedFile::fake()->image('cover.jpg'))->toMediaCollection('cover');

        return ['id' => $media->id];
    });
}

function issueTenantBToken(string $capability): string
{
    $roleId = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => Role::factory()->create([
            'tenant_id' => TenantFixture::TENANT_B,
            'name' => 'Tenant B Role '.$capability,
            'capabilities' => [$capability],
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

it('renders request.not_found, not missing_capability, for a foreign tenant event on GET', function () {
    $token = issueTenantBToken('events.view');

    test()->getJson('/v1/events/'.EventFixture::EVENT_A.'/media', [
        'X-Tenant-Id' => TenantFixture::TENANT_B,
        'Authorization' => 'Bearer '.$token,
    ])
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'request.not_found')
        ->assertJsonPath('status', 404);
});

it('renders request.not_found, not missing_capability, for a foreign tenant event on POST', function () {
    $token = issueTenantBToken('events.manage');

    test()->post(
        '/v1/events/'.EventFixture::EVENT_A.'/media',
        ['file' => UploadedFile::fake()->image('cover.jpg'), 'collection' => 'cover'],
        [
            'X-Tenant-Id' => TenantFixture::TENANT_B,
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'multipart/form-data',
            'Accept' => 'application/json',
        ],
    )
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'request.not_found')
        ->assertJsonPath('status', 404);
});

it('renders request.not_found, not missing_capability, for a foreign tenant media id on DELETE', function () {
    $media = attachIsolationCover();

    $token = issueTenantBToken('events.manage');

    test()->deleteJson('/v1/media/'.$media['id'], [], [
        'X-Tenant-Id' => TenantFixture::TENANT_B,
        'Authorization' => 'Bearer '.$token,
    ])
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'request.not_found')
        ->assertJsonPath('status', 404);
});
