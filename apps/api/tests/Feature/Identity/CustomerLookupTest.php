<?php

use App\Identity\Capability;
use App\Identity\Models\Customer;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\TenantStaff;

/*
 * Stage-07 plan, task breakdown item 10: GET /v1/customers, the staff
 * customer lookup implemented in the Identity context since Orders
 * never touches customers. Cursor-paginated, filters email (exact) and
 * name (prefix), capability customers.view.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $this->otherTenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    foreach ([$this->tenantId, $this->otherTenantId] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('memberships')->where('tenant_id', $tenantId)->delete();
            DB::table('roles')->where('tenant_id', $tenantId)->delete();
            DB::table('customers')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKey($this->tenantId)->delete();
        Tenant::query()->whereKey($this->otherTenantId)->delete();
    });

    User::query()->delete();
});

function lookupCustomers(string $tenantId): void
{
    app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
        Customer::factory()->create(['tenant_id' => $tenantId, 'email' => 'ada@example.com', 'name' => 'Ada Lovelace']);
        Customer::factory()->create(['tenant_id' => $tenantId, 'email' => 'grace@example.com', 'name' => 'Grace Hopper']);
        Customer::factory()->create(['tenant_id' => $tenantId, 'email' => 'alan@example.com', 'name' => 'Alan Turing']);
    });
}

it('lists customers cursor-paginated with the summary shape', function (): void {
    lookupCustomers($this->tenantId);

    $response = $this->getJson('/v1/customers?per_page=2', [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CustomersView),
        'X-Tenant-Id' => $this->tenantId,
    ]);

    $response->assertStatus(200)->assertConformsToOpenApi();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('data.0'))->toHaveKeys(['id', 'email', 'name', 'created_at'])
        ->and($response->json('meta.next_cursor'))->not->toBeNull();

    $next = $this->getJson('/v1/customers?per_page=2&cursor='.$response->json('meta.next_cursor'), [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CustomersView),
        'X-Tenant-Id' => $this->tenantId,
    ]);

    $next->assertStatus(200);

    expect($next->json('data'))->toHaveCount(1)
        ->and(array_merge(
            array_column($response->json('data'), 'id'),
            array_column($next->json('data'), 'id'),
        ))->toHaveCount(3);
});

it('filters by exact email', function (): void {
    lookupCustomers($this->tenantId);

    $response = $this->getJson('/v1/customers?filter[email]=ada@example.com', [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CustomersView),
        'X-Tenant-Id' => $this->tenantId,
    ]);

    $response->assertStatus(200);

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.email'))->toBe('ada@example.com');

    $partial = $this->getJson('/v1/customers?filter[email]=ada', [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CustomersView),
        'X-Tenant-Id' => $this->tenantId,
    ]);

    expect($partial->json('data'))->toHaveCount(0);
});

it('filters by name prefix', function (): void {
    lookupCustomers($this->tenantId);

    $response = $this->getJson('/v1/customers?filter[name]=A', [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CustomersView),
        'X-Tenant-Id' => $this->tenantId,
    ]);

    $response->assertStatus(200);

    expect(array_column($response->json('data'), 'name'))->toEqualCanonicalizing(['Ada Lovelace', 'Alan Turing']);
});

it('rejects unknown filter parameters with invalid_query_parameter', function (): void {
    $response = $this->getJson('/v1/customers?filter[phone]=555', [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CustomersView),
        'X-Tenant-Id' => $this->tenantId,
    ]);

    $response->assertStatus(400);
    $response->assertJsonPath('code', 'invalid_query_parameter');
});

it('denies staff without customers.view', function (): void {
    $response = $this->getJson('/v1/customers', [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::OrdersView),
        'X-Tenant-Id' => $this->tenantId,
    ]);

    $response->assertStatus(403);
    $response->assertJsonPath('code', 'missing_capability');
});

it('never exposes another tenant\'s customers', function (): void {
    lookupCustomers($this->otherTenantId);

    $response = $this->getJson('/v1/customers', [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CustomersView),
        'X-Tenant-Id' => $this->tenantId,
    ]);

    $response->assertStatus(200);

    expect($response->json('data'))->toBe([]);
});
