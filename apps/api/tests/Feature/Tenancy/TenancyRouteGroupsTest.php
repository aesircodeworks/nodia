<?php

use App\Http\Middleware\RequireCapability;
use App\Identity\Capability;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Http\Middleware\PlatformRequestTransaction;
use App\Tenancy\Http\Middleware\ResolveTenantFromHeader;
use App\Tenancy\Http\Middleware\ResolveTenantFromHost;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\MigratedDatabase;
use Tests\Support\PlatformStaff;
use Tests\Support\PostgresTestDatabase;

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    app(TenantTransaction::class)->asTenant($sentinel, function (): void {
        DB::table('memberships')->delete();
    });

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });

    User::query()->delete();
});

it('registers the three tenancy middleware groups, the platform group rebound to Passport bearer plus tenants.manage', function () {
    $router = app('router');

    $groups = $router->getMiddlewareGroups();

    expect($groups)->toHaveKeys(['tenancy.platform', 'tenancy.admin', 'tenancy.storefront'])
        ->and($groups['tenancy.platform'])->toBe([
            'auth:staff',
            PlatformRequestTransaction::class,
            RequireCapability::class.':'.Capability::TenantsManage->value,
        ])
        ->and($groups['tenancy.admin'])->toBe(['auth:staff', ResolveTenantFromHeader::class])
        ->and($groups['tenancy.storefront'])->toBe([ResolveTenantFromHost::class]);
});

it('runs platform group requests under the platform role posture with the sentinel tenant id', function () {
    Route::middleware('tenancy.platform')->prefix('v1')->get('/__probe/platform-posture', function () {
        return response()->json([
            'role' => DB::selectOne('select current_user as role')->role,
            'tenant_setting' => DB::selectOne("select current_setting('app.tenant_id', true) as tenant_id")->tenant_id,
            'transaction_level' => DB::transactionLevel(),
            'is_platform' => app(TenantContext::class)->isPlatform(),
            'context_tenant_id' => app(TenantContext::class)->tenantId(),
        ]);
    });

    $sentinel = config()->string('tenancy.platform_tenant_id');
    $token = PlatformStaff::token();

    $this->getJson('/v1/__probe/platform-posture', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJson([
            'role' => 'nodia_platform',
            'tenant_setting' => $sentinel,
            'transaction_level' => 1,
            'is_platform' => true,
            'context_tenant_id' => $sentinel,
        ]);

    expect(app(TenantContext::class)->hasTenant())->toBeFalse()
        ->and(DB::transactionLevel())->toBe(0);
});

it('denies platform group requests with a 401 problem document when no bearer is presented', function () {
    Route::middleware('tenancy.platform')->prefix('v1')->get('/__probe/platform-auth', fn () => response()->noContent());

    $this->getJson('/v1/__probe/platform-auth')
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertMatchesProblemSchema()
        ->assertJsonPath('code', 'auth.unauthenticated')
        ->assertJsonPath('status', 401);
});

it('denies platform group requests with a 403 problem document when the bearer lacks tenants.manage', function () {
    Route::middleware('tenancy.platform')->prefix('v1')->get('/__probe/platform-auth', fn () => response()->noContent());

    $token = PlatformStaff::token(Capability::EventsView);

    $this->getJson('/v1/__probe/platform-auth', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(403)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertMatchesProblemSchema()
        ->assertJsonPath('code', 'missing_capability')
        ->assertJsonPath('status', 403);
});

it('passes platform group requests through for a bearer holding tenants.manage', function () {
    Route::middleware('tenancy.platform')->prefix('v1')->get('/__probe/platform-auth', fn () => response()->noContent());

    $token = PlatformStaff::token();

    $this->getJson('/v1/__probe/platform-auth', ['Authorization' => 'Bearer '.$token])->assertNoContent();
});

it('rolls back writes made inside the platform transaction when the handler fails', function () {
    Route::middleware('tenancy.platform')->prefix('v1')->get('/__probe/platform-throwing', function () {
        Tenant::factory()->create();

        throw new RuntimeException('handler failure after a write');
    });

    $token = PlatformStaff::token();

    $this->getJson('/v1/__probe/platform-throwing', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(500)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'server.internal_error');

    $written = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->count(),
    );

    expect($written)->toBe(0);
});
