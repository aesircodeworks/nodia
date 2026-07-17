<?php

use App\Identity\Models\Customer;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-03 plan, task breakdown item 13: POST /v1/auth/customer/token,
 * /refresh, and /logout. Tenant is resolved from the Host header
 * (tenancy.storefront), never X-Tenant-Id (system-design 4.1); every
 * case that needs a real tenant creates one plus a resolvable
 * tenant_domains row through TenantTransaction::asPlatform(), mirroring
 * tests/Feature/Tenancy/TenantResolutionTest.php's own precedent.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    // customers carries no platform write policy (stage-03 plan Data
    // model, "standard single-table policy" precedent from memberships),
    // so a blanket nodia_platform delete silently affects zero rows;
    // each tenant's own customers must be cleared under nodia_app before
    // the tenant delete below, or it fails a foreign key violation
    // (customers_tenant_id_foreign), mirroring
    // tests/Contract/DocumentedResponseCoverageTest.php's own afterEach.
    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('customers')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

/**
 * @return array{tenant: Tenant, host: string}
 */
function customerAuthTenant(): array
{
    return app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create();
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        return ['tenant' => $tenant, 'host' => $domain->domain];
    });
}

/**
 * @param  array<string, mixed>  $attributes
 */
function createCustomerFor(string $tenantId, array $attributes = []): Customer
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Customer::factory()->create(['tenant_id' => $tenantId, ...$attributes]),
    );
}

function decodeCustomerAccessToken(string $jwt): Plain
{
    /** @var Plain $token */
    $token = (new Parser(new JoseEncoder))->parse($jwt);

    return $token;
}

describe('POST /v1/auth/customer/token', function (): void {
    it('issues a token pair carrying identity_type customer and tenant_id', function (): void {
        ['tenant' => $tenant, 'host' => $host] = customerAuthTenant();
        createCustomerFor($tenant->id, ['email' => 'buyer@example.com', 'password' => 'password']);

        $response = $this->postJson('http://'.$host.'/v1/auth/customer/token', [
            'email' => 'buyer@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()->assertConformsToOpenApi();

        $claims = decodeCustomerAccessToken($response->json('access_token'))->claims();

        expect($claims->get('identity_type'))->toBe('customer')
            ->and($claims->get('tenant_id'))->toBe($tenant->id);
    });

    it('rejects an unknown email with invalid_credentials', function (): void {
        ['host' => $host] = customerAuthTenant();

        $this->postJson('http://'.$host.'/v1/auth/customer/token', [
            'email' => 'nobody@example.com',
            'password' => 'password',
        ])
            ->assertStatus(401)
            ->assertConformsToOpenApi()
            ->assertJson(['code' => 'invalid_credentials']);
    });

    it('rejects a wrong password with invalid_credentials', function (): void {
        ['tenant' => $tenant, 'host' => $host] = customerAuthTenant();
        createCustomerFor($tenant->id, ['email' => 'buyer2@example.com', 'password' => 'password']);

        $this->postJson('http://'.$host.'/v1/auth/customer/token', [
            'email' => 'buyer2@example.com',
            'password' => 'wrong-password',
        ])
            ->assertStatus(401)
            ->assertJson(['code' => 'invalid_credentials']);
    });

    it('rejects an unclaimed guest account with invalid_credentials', function (): void {
        ['tenant' => $tenant, 'host' => $host] = customerAuthTenant();
        createCustomerFor($tenant->id, ['email' => 'guest@example.com', 'password' => null]);

        $this->postJson('http://'.$host.'/v1/auth/customer/token', [
            'email' => 'guest@example.com',
            'password' => 'anything',
        ])
            ->assertStatus(401)
            ->assertConformsToOpenApi()
            ->assertJson(['code' => 'invalid_credentials']);
    });
});

describe('POST /v1/auth/customer/refresh', function (): void {
    it('rotates the refresh token and revokes the previous one', function (): void {
        ['tenant' => $tenant, 'host' => $host] = customerAuthTenant();
        createCustomerFor($tenant->id, ['email' => 'refresh@example.com', 'password' => 'password']);

        $pair = $this->postJson('http://'.$host.'/v1/auth/customer/token', [
            'email' => 'refresh@example.com',
            'password' => 'password',
        ])->json();

        $rotated = $this->postJson('http://'.$host.'/v1/auth/customer/refresh', [
            'refresh_token' => $pair['refresh_token'],
        ]);

        $rotated->assertOk()->assertConformsToOpenApi();

        $this->postJson('http://'.$host.'/v1/auth/customer/refresh', [
            'refresh_token' => $pair['refresh_token'],
        ])
            ->assertStatus(401)
            ->assertJson(['code' => 'refresh_token_reused']);
    });

    it('rejects an unknown refresh token with invalid_refresh_token', function (): void {
        ['host' => $host] = customerAuthTenant();

        $this->postJson('http://'.$host.'/v1/auth/customer/refresh', [
            'refresh_token' => 'not-a-real-token',
        ])
            ->assertStatus(401)
            ->assertConformsToOpenApi()
            ->assertJson(['code' => 'invalid_refresh_token']);
    });
});

describe('POST /v1/auth/customer/logout', function (): void {
    it('revokes the access token and its refresh token', function (): void {
        ['tenant' => $tenant, 'host' => $host] = customerAuthTenant();
        createCustomerFor($tenant->id, ['email' => 'logout@example.com', 'password' => 'password']);

        $pair = $this->postJson('http://'.$host.'/v1/auth/customer/token', [
            'email' => 'logout@example.com',
            'password' => 'password',
        ])->json();

        $this->postJson('http://'.$host.'/v1/auth/customer/logout', [], ['Authorization' => 'Bearer '.$pair['access_token']])
            ->assertNoContent()
            ->assertConformsToOpenApi();

        // Illuminate\Auth\AuthManager caches a resolved guard for the life
        // of the container (tests/Feature/Identity/StaffLogoutTest.php's
        // own precedent, same root cause).
        Auth::forgetGuards();

        $this->postJson('http://'.$host.'/v1/auth/customer/logout', [], ['Authorization' => 'Bearer '.$pair['access_token']])
            ->assertStatus(401);

        $this->postJson('http://'.$host.'/v1/auth/customer/refresh', ['refresh_token' => $pair['refresh_token']])
            ->assertStatus(401);
    });

    it('is unauthenticated without a bearer token', function (): void {
        ['host' => $host] = customerAuthTenant();

        $this->postJson('http://'.$host.'/v1/auth/customer/logout')
            ->assertStatus(401)
            ->assertConformsToOpenApi()
            ->assertJson(['code' => 'auth.unauthenticated']);
    });

    it('rejects a bearer issued for a different tenant with tenant_mismatch', function (): void {
        ['tenant' => $tenantA, 'host' => $hostA] = customerAuthTenant();
        ['host' => $hostB] = customerAuthTenant();

        createCustomerFor($tenantA->id, ['email' => 'mismatch@example.com', 'password' => 'password']);

        $pair = $this->postJson('http://'.$hostA.'/v1/auth/customer/token', [
            'email' => 'mismatch@example.com',
            'password' => 'password',
        ])->json();

        $this->postJson('http://'.$hostB.'/v1/auth/customer/logout', [], ['Authorization' => 'Bearer '.$pair['access_token']])
            ->assertStatus(401)
            ->assertConformsToOpenApi()
            ->assertJson(['code' => 'tenant_mismatch']);
    });
});
