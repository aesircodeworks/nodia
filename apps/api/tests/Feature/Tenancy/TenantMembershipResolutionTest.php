<?php

use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Database\Rls;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;

/*
 * Stage-03 plan, Slice 3 (task breakdown item 5): activating membership
 * validation in ResolveTenantFromHeader. Builds on the resolution
 * mechanics tests/Feature/Tenancy/TenantResolutionTest.php already proved
 * in Stage 2; every case here additionally carries a real staff bearer,
 * since auth:staff now runs ahead of ResolveTenantFromHeader in the
 * tenancy.admin group (TenancyServiceProvider).
 */

const MEMBERSHIP_TENANT_A = '019797f2-0000-7000-8000-0000000000aa';
const MEMBERSHIP_TENANT_B = '019797f2-0000-7000-8000-0000000000bb';

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::factory()->create(['id' => MEMBERSHIP_TENANT_A, 'name' => 'Membership Tenant A']);
        Tenant::factory()->create(['id' => MEMBERSHIP_TENANT_B, 'name' => 'Membership Tenant B']);
    });
});

afterEach(function (): void {
    // memberships carries no platform write policy (stage-03 plan, Data
    // model), so each row is cleared under nodia_app scoped to the tenant
    // it belongs to, the same posture it was written under; the sentinel
    // platform tenant is included because createPlatformMember() writes
    // its row there.
    foreach ([MEMBERSHIP_TENANT_A, MEMBERSHIP_TENANT_B, config()->string('tenancy.platform_tenant_id')] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function (): void {
            DB::table('memberships')->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    User::query()->delete();
});

function registerMembershipProbe(): void
{
    Route::middleware('tenancy.admin')->prefix('v1')->get('/__probe/membership-resolution', fn () => response()->json([
        'role' => DB::selectOne('select current_user as role')->role,
        'tenant_setting' => DB::selectOne("select current_setting('app.tenant_id', true) as tenant_id")->tenant_id,
        'context_tenant_id' => app(TenantContext::class)->tenantId(),
        'is_platform' => app(TenantContext::class)->isPlatform(),
    ]));
}

/**
 * @return array{0: User, 1: string}
 */
function createTenantMember(string $tenantId): array
{
    $user = User::factory()->create();
    $token = StaffTokens::issue($user);

    app(TenantTransaction::class)->asTenant($tenantId, function () use ($user, $tenantId): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'role_id' => Role::factory()->create(['tenant_id' => $tenantId])->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    return [$user, $token];
}

/**
 * Platform-scope memberships are unconditionally MFA-enforcing (stage-03
 * plan, MFA enforcement paragraph, task breakdown item 11), so this
 * helper confirms MFA after the password-only token exchange, mirroring
 * Tests\Support\PlatformStaff::token()'s own precedent, or every case
 * below that reaches the tenancy.admin probe would now fail with
 * mfa_enforcement_required instead of the resolution outcome under test.
 *
 * @return array{0: User, 1: string}
 */
function createPlatformMember(): array
{
    $user = User::factory()->create();
    $token = StaffTokens::issue($user);

    $user->forceFill(['mfa_enabled' => true, 'mfa_confirmed_at' => now()])->save();

    $platformTenantId = config()->string('tenancy.platform_tenant_id');

    app(TenantTransaction::class)->asTenant($platformTenantId, function () use ($user): void {
        Membership::factory()->platform()->create([
            'user_id' => $user->id,
            'role_id' => Role::query()->whereNull('tenant_id')->firstOrFail()->id,
        ]);
    });

    return [$user, $token];
}

it('lets a caller with a tenant-scope membership resolve the header tenant under the app role', function () {
    registerMembershipProbe();

    [, $token] = createTenantMember(MEMBERSHIP_TENANT_A);

    $this->getJson('/v1/__probe/membership-resolution', [
        'X-Tenant-Id' => MEMBERSHIP_TENANT_A,
        'Authorization' => 'Bearer '.$token,
    ])->assertOk()->assertJson([
        'role' => Rls::APP_ROLE,
        'tenant_setting' => MEMBERSHIP_TENANT_A,
        'context_tenant_id' => MEMBERSHIP_TENANT_A,
        'is_platform' => false,
    ]);
});

it('denies a caller with no membership anywhere', function () {
    registerMembershipProbe();

    $user = User::factory()->create();
    $token = StaffTokens::issue($user);

    $this->getJson('/v1/__probe/membership-resolution', [
        'X-Tenant-Id' => MEMBERSHIP_TENANT_A,
        'Authorization' => 'Bearer '.$token,
    ])->assertStatus(403)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertMatchesProblemSchema()
        ->assertJsonPath('code', 'tenant_access_denied')
        ->assertJsonPath('status', 403);
});

it('denies a caller whose only membership is in a different tenant', function () {
    registerMembershipProbe();

    [, $token] = createTenantMember(MEMBERSHIP_TENANT_A);

    $this->getJson('/v1/__probe/membership-resolution', [
        'X-Tenant-Id' => MEMBERSHIP_TENANT_B,
        'Authorization' => 'Bearer '.$token,
    ])->assertStatus(403)->assertJsonPath('code', 'tenant_access_denied');
});

it('lets a platform-scope member reach any existing tenant under the platform role', function () {
    registerMembershipProbe();

    [, $token] = createPlatformMember();

    $this->getJson('/v1/__probe/membership-resolution', [
        'X-Tenant-Id' => MEMBERSHIP_TENANT_B,
        'Authorization' => 'Bearer '.$token,
    ])->assertOk()->assertJson([
        'role' => Rls::PLATFORM_ROLE,
        'tenant_setting' => MEMBERSHIP_TENANT_B,
        'context_tenant_id' => MEMBERSHIP_TENANT_B,
        'is_platform' => true,
    ]);
});

it('still denies a platform-scope member against a tenant id that does not exist', function () {
    registerMembershipProbe();

    [, $token] = createPlatformMember();

    $this->getJson('/v1/__probe/membership-resolution', [
        'X-Tenant-Id' => '019797f2-dead-7000-8000-000000000000',
        'Authorization' => 'Bearer '.$token,
    ])->assertStatus(403)->assertJsonPath('code', 'tenant_access_denied');
});

it('is unauthenticated with no bearer token at all, regardless of the header', function () {
    registerMembershipProbe();

    $this->getJson('/v1/__probe/membership-resolution', ['X-Tenant-Id' => MEMBERSHIP_TENANT_A])
        ->assertStatus(401)
        ->assertJsonPath('code', 'auth.unauthenticated');
});

it('still returns 400 for header-shape failures once a valid staff bearer is presented', function (?string $header, string $code) {
    registerMembershipProbe();

    $user = User::factory()->create();
    $token = StaffTokens::issue($user);

    $headers = ['Authorization' => 'Bearer '.$token];

    if ($header !== null) {
        $headers['X-Tenant-Id'] = $header;
    }

    $this->getJson('/v1/__probe/membership-resolution', $headers)
        ->assertStatus(400)
        ->assertJsonPath('code', $code);
})->with([
    'missing header' => [null, 'missing_tenant_header'],
    'blank header' => ['   ', 'missing_tenant_header'],
    'malformed header' => ['not-a-uuid', 'invalid_tenant_header'],
]);

it('runs no other query before membership validation completes inside the lookup transaction', function () {
    Route::middleware('tenancy.admin')->prefix('v1')->get('/__probe/membership-query-order-marker', function () {
        DB::select('select 1 as membership_probe_marker');

        return response()->noContent();
    });

    [, $token] = createTenantMember(MEMBERSHIP_TENANT_A);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->getJson('/v1/__probe/membership-query-order-marker', [
        'X-Tenant-Id' => MEMBERSHIP_TENANT_A,
        'Authorization' => 'Bearer '.$token,
    ])->assertNoContent();

    $markerIndex = null;

    foreach ($queries as $index => $sql) {
        if (str_contains($sql, 'membership_probe_marker')) {
            $markerIndex = $index;

            break;
        }
    }

    expect($markerIndex)->not->toBeNull();

    $beforeMarker = implode(' | ', array_slice($queries, 0, $markerIndex));

    expect($beforeMarker)->toContain('"tenants"')
        ->toContain('"memberships"');
});

it('never runs the handler query when membership validation denies access', function () {
    Route::middleware('tenancy.admin')->prefix('v1')->get('/__probe/membership-query-order-denied', function () {
        DB::select('select 1 as membership_probe_marker');

        return response()->noContent();
    });

    $stranger = User::factory()->create();
    $token = StaffTokens::issue($stranger);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->getJson('/v1/__probe/membership-query-order-denied', [
        'X-Tenant-Id' => MEMBERSHIP_TENANT_A,
        'Authorization' => 'Bearer '.$token,
    ])->assertStatus(403);

    expect(collect($queries)->filter(fn ($sql) => str_contains($sql, 'membership_probe_marker')))->toBeEmpty();
});
