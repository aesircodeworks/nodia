<?php

use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Http\Middleware\PlatformAuthPlaceholder;
use App\Tenancy\Http\Middleware\PlatformRequestTransaction;
use App\Tenancy\Http\Middleware\ResolveTenantFromHeader;
use App\Tenancy\Http\Middleware\ResolveTenantFromHost;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete(),
    );
});

it('registers the three tenancy middleware groups and the platform auth alias', function () {
    $router = app('router');

    $groups = $router->getMiddlewareGroups();

    expect($groups)->toHaveKeys(['tenancy.platform', 'tenancy.admin', 'tenancy.storefront'])
        ->and($groups['tenancy.platform'])->toBe(['auth.platform', PlatformRequestTransaction::class])
        ->and($groups['tenancy.admin'])->toBe(['auth:staff', ResolveTenantFromHeader::class])
        ->and($groups['tenancy.storefront'])->toBe([ResolveTenantFromHost::class])
        ->and($router->getMiddleware()['auth.platform'] ?? null)->toBe(PlatformAuthPlaceholder::class);
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

    $this->getJson('/v1/__probe/platform-posture')
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

it('denies platform group requests with a 401 problem document outside the testing and local environments', function (string $environment) {
    Route::middleware('tenancy.platform')->prefix('v1')->get('/__probe/platform-auth', fn () => response()->noContent());

    app()->detectEnvironment(fn (): string => $environment);

    $this->getJson('/v1/__probe/platform-auth')
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertMatchesProblemSchema()
        ->assertJsonPath('code', 'auth.unauthenticated')
        ->assertJsonPath('status', 401);
})->with(['production', 'staging']);

it('passes platform group requests through in the local environment', function () {
    Route::middleware('tenancy.platform')->prefix('v1')->get('/__probe/platform-auth', fn () => response()->noContent());

    app()->detectEnvironment(fn (): string => 'local');

    $this->getJson('/v1/__probe/platform-auth')->assertNoContent();
});

it('rolls back writes made inside the platform transaction when the handler fails', function () {
    Route::middleware('tenancy.platform')->prefix('v1')->get('/__probe/platform-throwing', function () {
        Tenant::factory()->create();

        throw new RuntimeException('handler failure after a write');
    });

    $this->getJson('/v1/__probe/platform-throwing')
        ->assertStatus(500)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'server.internal_error');

    $written = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->count(),
    );

    expect($written)->toBe(0);
});
