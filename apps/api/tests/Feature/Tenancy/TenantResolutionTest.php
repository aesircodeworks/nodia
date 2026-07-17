<?php

use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Database\Rls;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;

/*
 * Slice 6 of the stage-02 plan: the resolution matrix for the storefront
 * population, the SET LOCAL proof, and the Octane no-leak simulation. The
 * tenancy.admin and tenancy.storefront groups ship without production
 * routes, so every test registers a test-only probe route inside the
 * group it exercises. The admin population's own resolution matrix,
 * including staff authentication and membership validation, moved to
 * tests/Feature/Tenancy/TenantMembershipResolutionTest.php once stage-03
 * task-05 put auth:staff ahead of ResolveTenantFromHeader in the
 * tenancy.admin group; the header-shape cases below stayed generic
 * (storefront only) so this file keeps proving Stage 2's own mechanics
 * without needing a staff bearer.
 */

const RESOLUTION_TENANT_A = '019797f0-0000-7000-8000-0000000000fa';
const RESOLUTION_TENANT_B = '019797f0-0000-7000-8000-0000000000fb';

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::factory()->create(['id' => RESOLUTION_TENANT_A, 'name' => 'Acme']);
        Tenant::factory()->create(['id' => RESOLUTION_TENANT_B, 'name' => 'Globex']);

        TenantDomain::factory()->create([
            'tenant_id' => RESOLUTION_TENANT_A,
            'domain' => 'acme.nodia.example',
            'is_primary' => true,
        ]);
        TenantDomain::factory()->create([
            'tenant_id' => RESOLUTION_TENANT_A,
            'domain' => 'tickets.acme.com',
        ]);
        TenantDomain::factory()->create([
            'tenant_id' => RESOLUTION_TENANT_B,
            'domain' => 'globex.nodia.example',
            'is_primary' => true,
        ]);
    });
});

afterEach(function (): void {
    // Deleted under nodia_app, scoped to the one tenant the membership
    // leak-test writes to: memberships carries no platform write policy
    // (stage-03 plan, Data model), so nodia_platform alone could not see
    // past its tenant_isolation policy to remove the row, and the
    // dangling FK would then block the tenant delete below. Harmless
    // no-op for every other test in this file, which creates none.
    app(TenantTransaction::class)->asTenant(RESOLUTION_TENANT_B, function (): void {
        DB::table('memberships')->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    User::query()->delete();
});

function registerResolutionProbe(string $group): void
{
    Route::middleware('tenancy.'.$group)->prefix('v1')->get('/__probe/resolution', fn () => response()->json([
        'role' => DB::selectOne('select current_user as role')->role,
        'tenant_setting' => DB::selectOne("select current_setting('app.tenant_id', true) as tenant_id")->tenant_id,
        'transaction_level' => DB::transactionLevel(),
        'context_tenant_id' => app(TenantContext::class)->tenantId(),
        'is_platform' => app(TenantContext::class)->isPlatform(),
    ]));
}

it('enforces the resolution matrix for the storefront population', function (string $group, ?string $host, array $headers, int $status, ?string $code, ?string $tenantId) {
    registerResolutionProbe($group);

    // The Host header cannot be injected through the headers argument:
    // Request::create() overwrites HTTP_HOST with the URI's host, so
    // storefront cases carry the host in the URL instead.
    $uri = ($host === null ? '' : 'http://'.$host).'/v1/__probe/resolution';

    $response = $this->getJson($uri, $headers);

    $response->assertStatus($status);

    if ($code !== null) {
        $response->assertHeader('Content-Type', 'application/problem+json')
            ->assertMatchesProblemSchema()
            ->assertJsonPath('code', $code)
            ->assertJsonPath('status', $status);

        return;
    }

    $response->assertJson([
        'role' => Rls::APP_ROLE,
        'tenant_setting' => $tenantId,
        'transaction_level' => 1,
        'context_tenant_id' => $tenantId,
        'is_platform' => false,
    ]);
})->with([
    'platform subdomain resolves' => ['storefront', 'acme.nodia.example', [], 200, null, RESOLUTION_TENANT_A],
    'custom domain resolves' => ['storefront', 'tickets.acme.com', [], 200, null, RESOLUTION_TENANT_A],
    'mixed-case host with port resolves' => ['storefront', 'Tickets.ACME.Com:8443', [], 200, null, RESOLUTION_TENANT_A],
    'unknown host' => ['storefront', 'unknown.example', [], 404, 'unknown_host', null],
]);

it('leaves no tenant context or transaction residue after a resolved request', function () {
    registerResolutionProbe('storefront');

    $this->getJson('http://acme.nodia.example/v1/__probe/resolution')->assertOk();

    expect(app(TenantContext::class)->hasTenant())->toBeFalse()
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::selectOne("select current_setting('app.tenant_id', true) as tenant_id")->tenant_id)->toBeIn([null, '']);
});

it('never leaks tenant context between sequential kernel handles on one worker process', function () {
    registerResolutionProbe('storefront');
    Route::middleware('tenancy.admin')->prefix('v1')->get('/__probe/admin-resolution', fn () => response()->json([
        'tenant_setting' => DB::selectOne("select current_setting('app.tenant_id', true) as tenant_id")->tenant_id,
        'context_tenant_id' => app(TenantContext::class)->tenantId(),
    ]));

    $staff = User::factory()->create();
    $token = StaffTokens::issue($staff);

    app(TenantTransaction::class)->asTenant(RESOLUTION_TENANT_B, function () use ($staff): void {
        Membership::factory()->create([
            'user_id' => $staff->id,
            'tenant_id' => RESOLUTION_TENANT_B,
            'role_id' => Role::factory()->create(['tenant_id' => RESOLUTION_TENANT_B])->id,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    $this->getJson('http://acme.nodia.example/v1/__probe/resolution')
        ->assertOk()
        ->assertJsonPath('tenant_setting', RESOLUTION_TENANT_A)
        ->assertJsonPath('context_tenant_id', RESOLUTION_TENANT_A);

    $this->getJson('/v1/__probe/admin-resolution', [
        'X-Tenant-Id' => RESOLUTION_TENANT_B,
        'Authorization' => 'Bearer '.$token,
    ])
        ->assertOk()
        ->assertJsonPath('tenant_setting', RESOLUTION_TENANT_B)
        ->assertJsonPath('context_tenant_id', RESOLUTION_TENANT_B);

    $this->getJson('http://globex.nodia.example/v1/__probe/resolution')
        ->assertOk()
        ->assertJsonPath('tenant_setting', RESOLUTION_TENANT_B)
        ->assertJsonPath('context_tenant_id', RESOLUTION_TENANT_B);

    expect(app(TenantContext::class)->hasTenant())->toBeFalse()
        ->and(DB::transactionLevel())->toBe(0);
});

it('rolls back writes made inside the tenant transaction when the handler fails', function () {
    Route::middleware('tenancy.storefront')->prefix('v1')->get('/__probe/storefront-throwing', function () {
        TenantDomain::query()->create([
            'tenant_id' => app(TenantContext::class)->tenantId(),
            'domain' => 'written-then-rolled-back.example',
        ]);

        throw new RuntimeException('handler failure after a write');
    });

    $this->getJson('http://acme.nodia.example/v1/__probe/storefront-throwing')
        ->assertStatus(500)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'server.internal_error');

    $written = app(TenantTransaction::class)->asPlatform(
        fn () => TenantDomain::query()->where('domain', 'written-then-rolled-back.example')->count(),
    );

    expect($written)->toBe(0);
});
