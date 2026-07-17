<?php

use App\Identity\Actions\AnonymizeCustomer;
use App\Identity\Exceptions\CustomerAlreadyAnonymizedException;
use App\Identity\Models\Customer;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-12 plan, Slice 1 Concurrency: "two parallel erasure calls for one
 * customer produce exactly one anonymization and one event; the
 * conditional UPDATE on anonymized_at is null admits one winner by
 * affected-row count; the loser surfaces 409." Targets
 * App\Identity\Actions\AnonymizeCustomer directly against real
 * PostgreSQL, the same level ExportClaimContentionTest exercises
 * App\Reporting\Models\Export::claim() at, rather than two racing HTTP
 * requests, since the guard under test is the customer table's own
 * conditional UPDATE, not the data_subject_requests partial unique index
 * (already the target of tests/Unit/Identity/DataSubjectRequestClaimTest.php).
 */
const WORKERS = 2;

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
            DB::table('customers')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

it('admits exactly one worker when WORKERS parallel erasures race one customer', function (): void {
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $customerId = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Customer::factory()->create(['tenant_id' => $tenantId, 'password' => 'password'])->id,
    );

    $results = ParallelRunner::run(WORKERS, function () use ($tenantId, $customerId): bool {
        return app(TenantTransaction::class)->asTenant($tenantId, function () use ($customerId): bool {
            $customer = Customer::query()->findOrFail($customerId);

            try {
                app(AnonymizeCustomer::class)($customer, (string) Str::uuid7());

                return true;
            } catch (CustomerAlreadyAnonymizedException) {
                return false;
            }
        });
    });

    $customer = app(TenantTransaction::class)->asTenant($tenantId, fn () => Customer::query()->findOrFail($customerId));

    $eventCount = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => OutboxEvent::query()->where('type', 'CustomerAnonymized')->count(),
    );

    $counts = array_count_values(array_map(fn (bool $won): string => $won ? 'won' : 'lost', $results));

    expect($counts['won'] ?? 0)->toBe(1)
        ->and($counts['lost'] ?? 0)->toBe(WORKERS - 1)
        ->and($customer->anonymized_at)->not->toBeNull()
        ->and($eventCount)->toBe(1);
});
