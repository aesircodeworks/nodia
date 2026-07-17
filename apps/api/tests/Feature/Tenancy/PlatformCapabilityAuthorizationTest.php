<?php

use App\Identity\Capability;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PlatformStaff;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-03 plan, task breakdown item 7: "Stage 2 alias rebinding ... so
 * tenant and domain CRUD require tenants.manage; add the Stage 2
 * endpoints to the authorization matrix dataset." This is that matrix for
 * the tenancy.platform surface, mirroring
 * tests/Feature/Identity/AuthorizationMatrixTest.php's coverage of the
 * tenancy.admin surface: every Stage 2 route now rejects a missing bearer
 * with auth.unauthenticated and a bearer whose acting membership lacks
 * tenants.manage with missing_capability, and lets a bearer that holds it
 * reach the real handler.
 */

const PLATFORM_AUTH_TENANT_ID = '019797f3-0000-7000-8000-0000000000aa';
const PLATFORM_AUTH_DOMAIN_ID = '019797f3-0000-7000-8000-0000000000ab';

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::factory()->create(['id' => PLATFORM_AUTH_TENANT_ID]);

        TenantDomain::factory()->create([
            'id' => PLATFORM_AUTH_DOMAIN_ID,
            'tenant_id' => PLATFORM_AUTH_TENANT_ID,
            'domain' => 'platform-auth.example.com',
        ]);
    });
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ([...$tenantIds, $sentinel] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
            DB::table('memberships')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });

    User::query()->delete();
});

function platformAuthPath(string $uri): string
{
    return strtr($uri, [
        '{tenant}' => PLATFORM_AUTH_TENANT_ID,
        '{domain}' => PLATFORM_AUTH_DOMAIN_ID,
    ]);
}

dataset('platform capability routes', [
    'create tenant' => ['POST', '/v1/tenants', ['name' => 'New Co', 'default_locale' => 'en', 'supported_locales' => ['en']], 201],
    'list tenants' => ['GET', '/v1/tenants', [], 200],
    'show tenant' => ['GET', '/v1/tenants/{tenant}', [], 200],
    'update tenant' => ['PATCH', '/v1/tenants/{tenant}', ['name' => 'Renamed'], 200],
    'register domain' => ['POST', '/v1/tenants/{tenant}/domains', ['domain' => 'new.example.com'], 201],
    'list domains' => ['GET', '/v1/tenants/{tenant}/domains', [], 200],
    'make domain primary' => ['PATCH', '/v1/tenant-domains/{domain}', ['is_primary' => true], 200],
    'remove domain' => ['DELETE', '/v1/tenant-domains/{domain}', [], 204],
]);

it('rejects an unauthenticated caller with auth.unauthenticated', function (string $method, string $uri, array $body) {
    $this->json($method, platformAuthPath($uri), $body)
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertMatchesProblemSchema()
        ->assertJsonPath('code', 'auth.unauthenticated')
        ->assertJsonPath('status', 401);
})->with('platform capability routes');

it('rejects an authenticated caller without tenants.manage with missing_capability', function (string $method, string $uri, array $body) {
    $token = PlatformStaff::token(Capability::EventsView);

    $this->json($method, platformAuthPath($uri), $body, ['Authorization' => 'Bearer '.$token])
        ->assertStatus(403)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertMatchesProblemSchema()
        ->assertJsonPath('code', 'missing_capability')
        ->assertJsonPath('status', 403);
})->with('platform capability routes');

it('lets an authenticated caller holding tenants.manage reach the handler', function (string $method, string $uri, array $body, int $status) {
    $token = PlatformStaff::token(Capability::TenantsManage);

    $this->json($method, platformAuthPath($uri), $body, ['Authorization' => 'Bearer '.$token])
        ->assertStatus($status);
})->with('platform capability routes');
