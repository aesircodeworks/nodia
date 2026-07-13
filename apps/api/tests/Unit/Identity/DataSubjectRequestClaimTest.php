<?php

declare(strict_types=1);

use App\Identity\Enums\DataSubjectRequestStatus;
use App\Identity\Models\Customer;
use App\Identity\Models\DataSubjectRequest;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-12 plan, task breakdown item 1 / Data model
 * "data_subject_requests": the pending-to-processing claim is a
 * conditional UPDATE checked by affected-row count, never
 * read-then-write. A second claimant against the same row wins zero
 * rows, mirroring tests/Unit/Support/Outbox/OutboxDeliveryTest.php's own
 * precedent for App\Support\Outbox\Models\OutboxDelivery::markProcessed.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $this->userId = app(TenantTransaction::class)->asPlatform(fn () => User::factory()->create()->id);

    $this->customerId = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Customer::factory()->create(['tenant_id' => $this->tenantId])->id,
    );
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('data_subject_requests')->where('tenant_id', $this->tenantId)->delete();
        DB::table('customers')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('users')->where('id', $this->userId)->delete();
        Tenant::query()->whereKey($this->tenantId)->delete();
    });
});

function pendingDataSubjectRequestFixture(string $tenantId, string $customerId, string $userId): DataSubjectRequest
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => DataSubjectRequest::factory()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'requested_by_user_id' => $userId,
        ]),
    );
}

it('claims a pending request exactly once', function (): void {
    $request = pendingDataSubjectRequestFixture($this->tenantId, $this->customerId, $this->userId);

    $won = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DataSubjectRequest::claim($request->id),
    );

    expect($won)->toBeTrue();

    $row = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DataSubjectRequest::query()->whereKey($request->id)->firstOrFail(),
    );

    expect($row->status)->toBe(DataSubjectRequestStatus::Processing);
});

it('gives the second claimant zero affected rows', function (): void {
    $request = pendingDataSubjectRequestFixture($this->tenantId, $this->customerId, $this->userId);

    $first = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DataSubjectRequest::claim($request->id),
    );

    $second = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DataSubjectRequest::claim($request->id),
    );

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse();

    $row = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DataSubjectRequest::query()->whereKey($request->id)->firstOrFail(),
    );

    expect($row->status)->toBe(DataSubjectRequestStatus::Processing);
});

it('completes a processing request exactly once and stamps completed_at', function (): void {
    test()->freezeTime();
    $frozen = now();

    $request = pendingDataSubjectRequestFixture($this->tenantId, $this->customerId, $this->userId);

    app(TenantTransaction::class)->asTenant($this->tenantId, fn () => DataSubjectRequest::claim($request->id));

    $completed = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DataSubjectRequest::complete($request->id),
    );

    expect($completed)->toBeTrue();

    $row = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DataSubjectRequest::query()->whereKey($request->id)->firstOrFail(),
    );

    expect($row->status)->toBe(DataSubjectRequestStatus::Completed)
        ->and($row->completed_at)->not->toBeNull()
        ->and($row->completed_at->getTimestamp())->toBe($frozen->getTimestamp());
});

it('refuses to complete a request that was never claimed', function (): void {
    $request = pendingDataSubjectRequestFixture($this->tenantId, $this->customerId, $this->userId);

    $completed = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DataSubjectRequest::complete($request->id),
    );

    expect($completed)->toBeFalse();

    $row = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DataSubjectRequest::query()->whereKey($request->id)->firstOrFail(),
    );

    expect($row->status)->toBe(DataSubjectRequestStatus::Pending);
});

it('fails a processing request exactly once without stamping completed_at', function (): void {
    $request = pendingDataSubjectRequestFixture($this->tenantId, $this->customerId, $this->userId);

    app(TenantTransaction::class)->asTenant($this->tenantId, fn () => DataSubjectRequest::claim($request->id));

    $failed = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DataSubjectRequest::fail($request->id),
    );

    expect($failed)->toBeTrue();

    $row = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DataSubjectRequest::query()->whereKey($request->id)->firstOrFail(),
    );

    expect($row->status)->toBe(DataSubjectRequestStatus::Failed)
        ->and($row->completed_at)->toBeNull();
});
