<?php

use App\Payments\Actions\PruneWebhookPayloads;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-12 plan, Slice 3, task breakdown item 7 Unit: a zero or
 * negative retention window refuses to run rather than deleting
 * everything.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant(
        config()->string('tenancy.platform_tenant_id'),
        fn () => DB::table('gateway_webhook_events')->delete(),
    );
});

dataset('non-positive windows', [0, -1, -90]);

it('refuses to run and touches no row when the configured window is zero or negative', function (int $days): void {
    config()->set('retention.webhook_payload_days', $days);

    $id = (string) Str::uuid7();

    app(TenantTransaction::class)->asTenant(
        config()->string('tenancy.platform_tenant_id'),
        function () use ($id): void {
            DB::table('gateway_webhook_events')->insert([
                'id' => $id,
                'tenant_id' => config()->string('tenancy.platform_tenant_id'),
                'gateway' => 'fake',
                'gateway_event_id' => 'evt_'.Str::uuid7(),
                'payload' => json_encode(['type' => 'payment.confirmed']),
                'status' => 'processed',
                'received_at' => now()->subYears(10),
                'processed_at' => now()->subYears(10),
                'created_at' => now()->subYears(10),
                'updated_at' => now()->subYears(10),
            ]);
        },
    );

    expect(fn () => app(PruneWebhookPayloads::class)())->toThrow(RuntimeException::class);

    $row = app(TenantTransaction::class)->asTenant(
        config()->string('tenancy.platform_tenant_id'),
        fn () => DB::table('gateway_webhook_events')->where('id', $id)->first(),
    );

    expect($row->payload)->not->toBeNull()
        ->and($row->payload_pruned_at)->toBeNull();
})->with('non-positive windows');
