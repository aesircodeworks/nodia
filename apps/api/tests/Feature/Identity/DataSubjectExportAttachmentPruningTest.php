<?php

use App\Identity\Actions\PruneDataSubjectExportAttachments;
use App\Identity\Capability;
use App\Identity\Enums\DataSubjectRequestStatus;
use App\Identity\Enums\DataSubjectRequestType;
use App\Identity\Models\Customer;
use App\Identity\Models\DataSubjectRequest;
use App\Models\User;
use App\Payments\Enums\LedgerAccount;
use App\Payments\Enums\LedgerDirection;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\TenantStaff;

/*
 * Stage-12 plan, Slice 3, task breakdown item 7: the data subject
 * export attachment pruner. Deletes a completed export request's
 * data_subject_export medialibrary attachment once completed_at is
 * older than config('retention.export_attachment_days'); the request
 * row survives as audit trail and GET /v1/data-subject-requests/
 * {data_subject_request} then reads download_url as null.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    Storage::fake('media');

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $this->requestedByUserId = app(TenantTransaction::class)->asPlatform(fn () => User::factory()->create()->id);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        foreach (['media', 'data_subject_requests', 'customers', 'memberships'] as $table) {
            DB::table($table)->where('tenant_id', $this->tenantId)->delete();
        }
    });

    DB::statement('truncate ledger_entries');

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    User::query()->delete();
});

function completedExportRequestWithAttachment(string $tenantId, string $requestedByUserId, CarbonImmutable $completedAt): string
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $requestedByUserId, $completedAt): string {
        $customer = Customer::factory()->create(['tenant_id' => $tenantId]);

        $request = DataSubjectRequest::factory()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $customer->id,
            'type' => DataSubjectRequestType::Export,
            'status' => DataSubjectRequestStatus::Completed,
            'requested_by_user_id' => $requestedByUserId,
            'completed_at' => $completedAt,
        ]);

        $request->addMediaFromString('{"customer":{}}')
            ->usingFileName('export.json')
            ->toMediaCollection('data_subject_export');

        return $request->id;
    });
}

it('deletes the attachment for a completed export request past the window, leaving the request row and reading download_url as null', function (): void {
    $this->freezeTime();
    $now = now();

    $requestId = completedExportRequestWithAttachment($this->tenantId, $this->requestedByUserId, $now->copy()->subDays(8));

    $pruned = app(PruneDataSubjectExportAttachments::class)();

    expect($pruned)->toBe(1);

    $request = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DataSubjectRequest::query()->findOrFail($requestId),
    );

    expect($request->status)->toBe(DataSubjectRequestStatus::Completed)
        ->and($request->completed_at->toDateTimeString())->toBe($now->copy()->subDays(8)->toDateTimeString());

    $media = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => $request->getMedia('data_subject_export'),
    );
    expect($media)->toBeEmpty();

    $this->getJson('/v1/data-subject-requests/'.$requestId, [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CustomersExport),
        'X-Tenant-Id' => $this->tenantId,
    ])
        ->assertOk()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('download_url', null);
});

it('leaves a completed export request inside the window untouched', function (): void {
    $this->freezeTime();
    $now = now();

    $requestId = completedExportRequestWithAttachment($this->tenantId, $this->requestedByUserId, $now->copy()->subDays(2));

    $pruned = app(PruneDataSubjectExportAttachments::class)();

    expect($pruned)->toBe(0);

    $request = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DataSubjectRequest::query()->findOrFail($requestId),
    );

    $media = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => $request->getMedia('data_subject_export'),
    );
    expect($media)->toHaveCount(1);

    $response = $this->getJson('/v1/data-subject-requests/'.$requestId, [
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::CustomersExport),
        'X-Tenant-Id' => $this->tenantId,
    ]);

    $response->assertOk()->assertJsonPath('status', 'completed');
    expect($response->json('download_url'))->toBeString()->not->toBeEmpty();
});

it('is a no-op on requests already pruned when the pruner runs again', function (): void {
    $this->freezeTime();
    $now = now();

    $requestId = completedExportRequestWithAttachment($this->tenantId, $this->requestedByUserId, $now->copy()->subDays(8));

    $first = app(PruneDataSubjectExportAttachments::class)();
    expect($first)->toBe(1);

    $this->travel(1)->day();

    $second = app(PruneDataSubjectExportAttachments::class)();
    expect($second)->toBe(0);
});

it('never touches ledger entries while pruning export attachments', function (): void {
    $this->freezeTime();
    $now = now();

    $entryId = (string) Str::uuid7();
    $sourceEventId = (string) Str::uuid7();
    $referenceId = (string) Str::uuid7();

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($entryId, $sourceEventId, $referenceId): void {
        DB::table('ledger_entries')->insert([
            'id' => $entryId,
            'tenant_id' => $this->tenantId,
            'account' => LedgerAccount::GatewayReceivable->value,
            'direction' => LedgerDirection::Debit->value,
            'amount' => 1_000,
            'currency' => 'USD',
            'reference_type' => 'payment',
            'reference_id' => $referenceId,
            'source_event_id' => $sourceEventId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    completedExportRequestWithAttachment($this->tenantId, $this->requestedByUserId, $now->copy()->subDays(8));

    app(PruneDataSubjectExportAttachments::class)();

    $stillThere = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DB::table('ledger_entries')->where('id', $entryId)->first(),
    );

    expect($stillThere)->not->toBeNull()
        ->and((int) $stillThere->amount)->toBe(1_000);
});
