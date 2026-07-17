<?php

use App\Identity\Capability;
use App\Identity\Enums\DataSubjectRequestStatus;
use App\Identity\Enums\DataSubjectRequestType;
use App\Identity\Models\Customer;
use App\Identity\Models\DataSubjectRequest;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\TenantStaff;

/*
 * Stage-12 plan, Endpoints "GET /v1/data-subject-requests/{data_subject_
 * request}" (task breakdown item 6): "Returns DataSubjectRequestData. For
 * a completed export, download_url is a time-limited signed URL to the
 * medialibrary attachment; before completion it is null. 404 across
 * tenants; 403 without either capability."
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    Storage::fake(config()->string('media.protected_disk'));

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $this->otherTenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $this->requestedByUserId = app(TenantTransaction::class)->asPlatform(fn () => User::factory()->create()->id);
});

afterEach(function (): void {
    foreach ([$this->tenantId, $this->otherTenantId] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            foreach (['media', 'data_subject_requests', 'customers', 'memberships'] as $table) {
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
function dsrShowRequest(string $tenantId, string $requestedByUserId, array $attributes = []): DataSubjectRequest
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $requestedByUserId, $attributes): DataSubjectRequest {
        $customer = Customer::factory()->create(['tenant_id' => $tenantId]);

        return DataSubjectRequest::factory()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $customer->id,
            'requested_by_user_id' => $requestedByUserId,
            ...$attributes,
        ]);
    });
}

function dsrCompleteExport(string $tenantId, string $requestId): void
{
    app(TenantTransaction::class)->asTenant($tenantId, function () use ($requestId): void {
        DataSubjectRequest::claim($requestId);

        $request = DataSubjectRequest::query()->findOrFail($requestId);
        $request->addMediaFromString('{"customer":{}}')
            ->usingFileName('export.json')
            ->toMediaCollection('data_subject_export');

        DataSubjectRequest::complete($requestId);
    });
}

it('returns null download_url for a pending export request', function (): void {
    $request = dsrShowRequest($this->tenantId, $this->requestedByUserId, [
        'type' => DataSubjectRequestType::Export,
        'status' => DataSubjectRequestStatus::Pending,
    ]);

    $this->getJson('/v1/data-subject-requests/'.$request->id, [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CustomersExport),
        'X-Tenant-Id' => $this->tenantId,
    ])
        ->assertOk()
        ->assertConformsToOpenApi()
        ->assertJsonPath('id', $request->id)
        ->assertJsonPath('customer_id', $request->customer_id)
        ->assertJsonPath('type', 'export')
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('download_url', null);
});

it('returns a time-limited signed download_url for a completed export', function (): void {
    $request = dsrShowRequest($this->tenantId, $this->requestedByUserId, [
        'type' => DataSubjectRequestType::Export,
        'status' => DataSubjectRequestStatus::Pending,
    ]);

    dsrCompleteExport($this->tenantId, $request->id);

    $response = $this->getJson('/v1/data-subject-requests/'.$request->id, [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CustomersExport),
        'X-Tenant-Id' => $this->tenantId,
    ]);

    $response->assertOk()
        ->assertConformsToOpenApi()
        ->assertJsonPath('status', 'completed');

    expect($response->json('download_url'))->toBeString()->not->toBeEmpty();
});

it('returns null download_url for a completed erasure, which never attaches a file', function (): void {
    $request = dsrShowRequest($this->tenantId, $this->requestedByUserId, [
        'type' => DataSubjectRequestType::Erasure,
        'status' => DataSubjectRequestStatus::Completed,
        'completed_at' => now(),
    ]);

    $this->getJson('/v1/data-subject-requests/'.$request->id, [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CustomersErase),
        'X-Tenant-Id' => $this->tenantId,
    ])
        ->assertOk()
        ->assertConformsToOpenApi()
        ->assertJsonPath('type', 'erasure')
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('download_url', null);
});

it('allows a caller who only holds customers.erase to view an export request', function (): void {
    $request = dsrShowRequest($this->tenantId, $this->requestedByUserId, ['type' => DataSubjectRequestType::Export]);

    $this->getJson('/v1/data-subject-requests/'.$request->id, [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CustomersErase),
        'X-Tenant-Id' => $this->tenantId,
    ])->assertOk()->assertConformsToOpenApi();
});

it('returns 403 without either capability', function (): void {
    $request = dsrShowRequest($this->tenantId, $this->requestedByUserId);

    $this->getJson('/v1/data-subject-requests/'.$request->id, [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::EventsView),
        'X-Tenant-Id' => $this->tenantId,
    ])
        ->assertStatus(403)
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'missing_capability');
});

it('returns 404 for an unknown id', function (): void {
    $this->getJson('/v1/data-subject-requests/'.Str::uuid7(), [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CustomersExport),
        'X-Tenant-Id' => $this->tenantId,
    ])
        ->assertStatus(404)
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'request.not_found');
});

it('returns 404 for a request belonging to another tenant', function (): void {
    $request = dsrShowRequest($this->otherTenantId, $this->requestedByUserId);

    $this->getJson('/v1/data-subject-requests/'.$request->id, [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CustomersExport),
        'X-Tenant-Id' => $this->tenantId,
    ])
        ->assertStatus(404)
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'request.not_found');
});
