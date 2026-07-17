<?php

use App\Identity\Actions\PruneDataSubjectExportAttachments;
use App\Identity\Enums\DataSubjectRequestStatus;
use App\Identity\Enums\DataSubjectRequestType;
use App\Identity\Models\Customer;
use App\Identity\Models\DataSubjectRequest;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-12 plan, Slice 3, task breakdown item 7 Unit: a zero or
 * negative retention window refuses to run rather than deleting every
 * attachment.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    Storage::fake(config()->string('media.protected_disk'));
});

afterEach(function (): void {
    $tenantId = $this->tenantId ?? null;

    if ($tenantId !== null) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            foreach (['media', 'data_subject_requests', 'customers'] as $table) {
                DB::table($table)->where('tenant_id', $tenantId)->delete();
            }
        });

        app(TenantTransaction::class)->asPlatform(
            fn () => Tenant::query()->whereKey($tenantId)->delete(),
        );
    }

    User::query()->delete();
});

dataset('non-positive windows', [0, -1, -30]);

it('refuses to run and deletes no attachment when the configured window is zero or negative', function (int $days): void {
    config()->set('retention.export_attachment_days', $days);

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $userId = app(TenantTransaction::class)->asPlatform(fn () => User::factory()->create()->id);

    $requestId = app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($userId): string {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenantId]);

        $request = DataSubjectRequest::factory()->create([
            'tenant_id' => $this->tenantId,
            'customer_id' => $customer->id,
            'type' => DataSubjectRequestType::Export,
            'status' => DataSubjectRequestStatus::Completed,
            'requested_by_user_id' => $userId,
            'completed_at' => now()->subYears(10),
        ]);

        $request->addMediaFromString('{"customer":{}}')
            ->usingFileName('export.json')
            ->toMediaCollection('data_subject_export');

        return $request->id;
    });

    expect(fn () => app(PruneDataSubjectExportAttachments::class)())->toThrow(RuntimeException::class);

    $media = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DataSubjectRequest::query()->findOrFail($requestId)->getMedia('data_subject_export'),
    );

    expect($media)->toHaveCount(1);
})->with('non-positive windows');
