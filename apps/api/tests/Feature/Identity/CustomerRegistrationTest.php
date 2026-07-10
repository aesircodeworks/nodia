<?php

use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-03 plan, task breakdown item 13: POST /v1/customers. Tenant is
 * resolved from the Host header (tenancy.storefront), never
 * X-Tenant-Id (system-design 4.1).
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
function registrationTenant(array $attributes = []): array
{
    return app(TenantTransaction::class)->asPlatform(function () use ($attributes): array {
        $tenant = Tenant::factory()->create($attributes);
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        return ['tenant' => $tenant, 'host' => $domain->domain];
    });
}

describe('POST /v1/customers', function (): void {
    it('creates a guest with no password', function (): void {
        ['host' => $host] = registrationTenant();

        $response = $this->postJson('http://'.$host.'/v1/customers', [
            'email' => 'guest@example.com',
            'name' => 'Guest Buyer',
        ]);

        $response->assertCreated()
            ->assertConformsToOpenApi()
            ->assertJson([
                'email' => 'guest@example.com',
                'name' => 'Guest Buyer',
                'is_claimed' => false,
            ]);

        expect($response->json())->not->toHaveKey('password');
    });

    it('registers with a password', function (): void {
        ['host' => $host] = registrationTenant();

        $response = $this->postJson('http://'.$host.'/v1/customers', [
            'email' => 'registered@example.com',
            'name' => 'Registered Buyer',
            'password' => 'a-real-password',
        ]);

        $response->assertCreated()
            ->assertConformsToOpenApi()
            ->assertJson(['is_claimed' => true]);

        $this->postJson('http://'.$host.'/v1/auth/customer/token', [
            'email' => 'registered@example.com',
            'password' => 'a-real-password',
        ])->assertOk();
    });

    it('falls back to the tenant default locale when locale is omitted', function (): void {
        ['host' => $host] = registrationTenant(['default_locale' => 'pt-BR', 'supported_locales' => ['pt-BR', 'en']]);

        $this->postJson('http://'.$host.'/v1/customers', [
            'email' => 'locale-fallback@example.com',
            'name' => 'Locale Fallback',
        ])->assertCreated()->assertJson(['locale' => 'pt-BR']);
    });

    it('honors an explicit locale over the tenant default', function (): void {
        ['host' => $host] = registrationTenant(['default_locale' => 'pt-BR', 'supported_locales' => ['pt-BR', 'en']]);

        $this->postJson('http://'.$host.'/v1/customers', [
            'email' => 'locale-explicit@example.com',
            'name' => 'Locale Explicit',
            'locale' => 'en',
        ])->assertCreated()->assertJson(['locale' => 'en']);
    });

    it('rejects a duplicate email in the same tenant with customer_email_taken', function (): void {
        ['host' => $host] = registrationTenant();

        $this->postJson('http://'.$host.'/v1/customers', [
            'email' => 'dupe@example.com',
            'name' => 'First',
        ])->assertCreated();

        $this->postJson('http://'.$host.'/v1/customers', [
            'email' => 'dupe@example.com',
            'name' => 'Second',
        ])
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJson(['code' => 'customer_email_taken']);
    });

    it('registers the same email independently under two different tenants', function (): void {
        ['host' => $hostA] = registrationTenant();
        ['host' => $hostB] = registrationTenant();

        $this->postJson('http://'.$hostA.'/v1/customers', [
            'email' => 'shared@example.com',
            'name' => 'Tenant A Buyer',
        ])->assertCreated();

        $this->postJson('http://'.$hostB.'/v1/customers', [
            'email' => 'shared@example.com',
            'name' => 'Tenant B Buyer',
        ])->assertCreated();
    });

    it('renders request.validation_failed for a blank name', function (): void {
        ['host' => $host] = registrationTenant();

        $this->postJson('http://'.$host.'/v1/customers', ['email' => 'no-name@example.com', 'name' => ''])
            ->assertStatus(422)
            ->assertConformsToOpenApi()
            ->assertJson(['code' => 'request.validation_failed']);
    });
});
