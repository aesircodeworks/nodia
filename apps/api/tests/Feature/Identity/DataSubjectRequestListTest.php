<?php

use App\Identity\Capability;
use App\Identity\Enums\DataSubjectRequestStatus;
use App\Identity\Enums\DataSubjectRequestType;
use App\Identity\Models\Customer;
use App\Identity\Models\DataSubjectRequest;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\TenantStaff;

/*
 * Stage-12 plan, Endpoints "GET /v1/data-subject-requests" (task
 * breakdown item 6): "Admin list via query-builder with explicit
 * allowlists: filter[customer_id], filter[type], filter[status],
 * sort=-created_at. Bounded collection, page pagination, standard
 * paginator envelope."
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $this->otherTenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $this->userId = app(TenantTransaction::class)->asPlatform(fn () => User::factory()->create()->id);
});

afterEach(function (): void {
    foreach ([$this->tenantId, $this->otherTenantId] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            foreach (['data_subject_requests', 'customers', 'memberships'] as $table) {
                DB::table($table)->where('tenant_id', $tenantId)->delete();
            }
        });
    }

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    User::query()->delete();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function dsrListRequest(string $tenantId, string $userId, array $attributes = []): DataSubjectRequest
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $userId, $attributes): DataSubjectRequest {
        $customerId = $attributes['customer_id'] ?? Customer::factory()->create(['tenant_id' => $tenantId])->id;

        return DataSubjectRequest::factory()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'requested_by_user_id' => $userId,
            ...$attributes,
        ]);
    });
}

it('lists data subject requests for the acting tenant, newest first', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-01T00:00:00Z'));
    $earlier = dsrListRequest($this->tenantId, $this->userId);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-02T00:00:00Z'));
    $later = dsrListRequest($this->tenantId, $this->userId);

    CarbonImmutable::setTestNow();

    $response = $this->getJson('/v1/data-subject-requests', [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CustomersExport),
        'X-Tenant-Id' => $this->tenantId,
    ]);

    $response->assertOk()->assertConformsToOpenApi();

    expect(array_column($response->json('data'), 'id'))->toBe([$later->id, $earlier->id])
        ->and($response->json('meta.total'))->toBe(2);
});

it('honors the customer_id, type, and status filters', function (): void {
    $customer = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Customer::factory()->create(['tenant_id' => $this->tenantId]),
    );

    $target = dsrListRequest($this->tenantId, $this->userId, [
        'customer_id' => $customer->id,
        'type' => DataSubjectRequestType::Export,
        'status' => DataSubjectRequestStatus::Completed,
    ]);
    dsrListRequest($this->tenantId, $this->userId, [
        'type' => DataSubjectRequestType::Erasure,
        'status' => DataSubjectRequestStatus::Pending,
    ]);

    $headers = [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CustomersExport),
        'X-Tenant-Id' => $this->tenantId,
    ];

    $byCustomer = $this->getJson('/v1/data-subject-requests?filter[customer_id]='.$customer->id, $headers);
    expect(array_column($byCustomer->json('data'), 'id'))->toBe([$target->id]);

    $byType = $this->getJson('/v1/data-subject-requests?filter[type]=export', $headers);
    expect(array_column($byType->json('data'), 'id'))->toBe([$target->id]);

    $byStatus = $this->getJson('/v1/data-subject-requests?filter[status]=completed', $headers);
    expect(array_column($byStatus->json('data'), 'id'))->toBe([$target->id]);
});

it('honors the created_at sort allowlist in both directions', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-01T00:00:00Z'));
    $first = dsrListRequest($this->tenantId, $this->userId);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-02T00:00:00Z'));
    $second = dsrListRequest($this->tenantId, $this->userId);

    CarbonImmutable::setTestNow();

    $headers = [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CustomersExport),
        'X-Tenant-Id' => $this->tenantId,
    ];

    $ascending = $this->getJson('/v1/data-subject-requests?sort=created_at', $headers);
    expect(array_column($ascending->json('data'), 'id'))->toBe([$first->id, $second->id]);

    $descending = $this->getJson('/v1/data-subject-requests?sort=-created_at', $headers);
    expect(array_column($descending->json('data'), 'id'))->toBe([$second->id, $first->id]);
});

it('rejects an unlisted filter parameter with invalid_query_parameter', function (): void {
    $this->getJson('/v1/data-subject-requests?filter[requested_by_user_id]=1', [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CustomersExport),
        'X-Tenant-Id' => $this->tenantId,
    ])
        ->assertStatus(400)
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'invalid_query_parameter');
});

it('rejects an unlisted sort parameter with invalid_query_parameter', function (): void {
    $this->getJson('/v1/data-subject-requests?sort=status', [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CustomersExport),
        'X-Tenant-Id' => $this->tenantId,
    ])
        ->assertStatus(400)
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'invalid_query_parameter');
});

it('never leaks another tenant\'s data subject request', function (): void {
    dsrListRequest($this->tenantId, $this->userId);
    dsrListRequest($this->otherTenantId, $this->userId);

    $response = $this->getJson('/v1/data-subject-requests', [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CustomersExport),
        'X-Tenant-Id' => $this->tenantId,
    ]);

    expect($response->json('data'))->toHaveCount(1);
});

it('returns 403 without either capability', function (): void {
    $this->getJson('/v1/data-subject-requests', [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::EventsView),
        'X-Tenant-Id' => $this->tenantId,
    ])
        ->assertStatus(403)
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'missing_capability');
});

it('rejects a request with no bearer', function (): void {
    $this->getJson('/v1/data-subject-requests', ['X-Tenant-Id' => $this->tenantId])
        ->assertStatus(401)
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'auth.unauthenticated');
});
