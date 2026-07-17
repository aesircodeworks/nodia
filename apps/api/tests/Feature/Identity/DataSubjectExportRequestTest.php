<?php

use App\Identity\Capability;
use App\Identity\Jobs\BuildDataSubjectExportJob;
use App\Identity\Models\Customer;
use App\Identity\Models\DataSubjectRequest;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\TenantStaff;

/*
 * Stage-12 plan, Slice 2 (task breakdown item 6): the export branch of
 * POST /v1/customers/{customer}/data-subject-requests. Queue::fake()
 * throughout, mirroring tests/Feature/Reporting/ExportLifecycleTest.php's
 * own reasoning: dispatching the real App\Identity\Jobs\
 * BuildDataSubjectExportJob and running the real assembler is exercised
 * end to end by tests/Feature/Identity/DataSubjectExportBuildTest.php;
 * this suite stays focused on the endpoint's own contract (the 202
 * response shape, the open-request conflict, authorization).
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    Queue::fake();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        foreach (['data_subject_requests', 'customers', 'memberships'] as $table) {
            DB::table($table)->where('tenant_id', $this->tenantId)->delete();
        }
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKey($this->tenantId)->delete();
    });

    User::query()->delete();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function exportCustomer(string $tenantId, array $attributes = []): Customer
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Customer::factory()->create(['tenant_id' => $tenantId, ...$attributes]),
    );
}

it('returns 202 with a pending request and dispatches the build job exactly once', function (): void {
    $customer = exportCustomer($this->tenantId);

    $response = $this->postJson('/v1/customers/'.$customer->id.'/data-subject-requests', ['type' => 'export'], [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CustomersExport),
        'X-Tenant-Id' => $this->tenantId,
    ]);

    $response->assertStatus(202)
        ->assertConformsToOpenApi()
        ->assertJsonPath('customer_id', $customer->id)
        ->assertJsonPath('type', 'export')
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('download_url', null)
        ->assertJsonPath('completed_at', null);

    Queue::assertPushed(BuildDataSubjectExportJob::class, 1);
    Queue::assertPushed(
        BuildDataSubjectExportJob::class,
        fn (BuildDataSubjectExportJob $job): bool => $job->requestId === $response->json('id'),
    );

    $fresh = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DataSubjectRequest::query()->findOrFail($response->json('id')),
    );

    expect($fresh->status->value)->toBe('pending');
});

it('returns 409 data_subject_request_already_open on a parallel duplicate export request', function (): void {
    $customer = exportCustomer($this->tenantId);
    $headers = [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CustomersExport),
        'X-Tenant-Id' => $this->tenantId,
    ];

    $this->postJson('/v1/customers/'.$customer->id.'/data-subject-requests', ['type' => 'export'], $headers)
        ->assertStatus(202);

    $this->postJson('/v1/customers/'.$customer->id.'/data-subject-requests', ['type' => 'export'], $headers)
        ->assertStatus(409)
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'data_subject_request_already_open');
});

it('returns 403 without customers.export', function (): void {
    $customer = exportCustomer($this->tenantId);

    $this->postJson('/v1/customers/'.$customer->id.'/data-subject-requests', ['type' => 'export'], [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CustomersErase),
        'X-Tenant-Id' => $this->tenantId,
    ])
        ->assertStatus(403)
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'missing_capability');
});
