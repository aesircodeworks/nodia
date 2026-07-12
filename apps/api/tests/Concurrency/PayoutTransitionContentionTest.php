<?php

use App\Payments\Actions\IngestGatewayWebhook;
use App\Payments\Enums\PayoutStatus;
use App\Payments\Enums\SubmerchantStatus;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Jobs\ProcessGatewayWebhook;
use App\Payments\Models\Payout;
use App\Payments\Models\SubmerchantAccount;
use App\Support\Money\Money;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;

/**
 * Stage-08c plan, Slice 5 concurrency rule: the same payout paid
 * webhook delivered on two parallel workers (two distinct gateway
 * event ids, bypassing raw-ingest dedup, so the transition-level
 * guard is what is under test) produces exactly one row, one
 * transition, and exactly one PayoutExecuted outbox event, mirroring
 * WebhookProcessingContentionTest's confirmation-webhook precedent.
 */
beforeEach(function (): void {
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('payouts')->where('tenant_id', $tenantId)->delete();
            DB::table('submerchant_accounts')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        DB::table('gateway_webhook_events')->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

it('applies exactly one transition and records exactly one PayoutExecuted event under parallel duplicate paid webhooks', function (): void {
    $tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(['enabled_gateways' => ['fake']])->id,
    );

    app(TenantTransaction::class)->asTenant($tenantId, fn () => SubmerchantAccount::factory()->create([
        'tenant_id' => $tenantId,
        'gateway' => 'fake',
        'status' => SubmerchantStatus::Active,
        'gateway_account_reference' => 'sm_ref_payout_race',
    ]));

    $gateway = app(FakeGateway::class);

    $createdDelivery = $gateway->payoutCreatedWebhook('sm_ref_payout_race', 'fake_po_race', Money::of(5000, 'USD'));
    $createdRowId = app(IngestGatewayWebhook::class)($gateway, $createdDelivery->body, $createdDelivery->headers);
    (new ProcessGatewayWebhook($createdRowId))->handle();

    $rowIds = array_map(function (string $eventId) use ($gateway): string {
        $delivery = $gateway->payoutStatusWebhook('sm_ref_payout_race', 'fake_po_race', PayoutStatus::Paid, eventId: $eventId);

        return app(IngestGatewayWebhook::class)($gateway, $delivery->body, $delivery->headers);
    }, ['evt_payout_parallel_1', 'evt_payout_parallel_2']);

    ParallelRunner::runEach(...array_map(
        fn (string $rowId): Closure => function () use ($rowId): bool {
            (new ProcessGatewayWebhook($rowId))->handle();

            return true;
        },
        $rowIds,
    ));

    [$payoutCount, $paidCount, $eventCount] = app(TenantTransaction::class)->asPlatform(fn (): array => [
        Payout::query()->where('gateway_reference', 'fake_po_race')->count(),
        Payout::query()->where('gateway_reference', 'fake_po_race')->where('status', PayoutStatus::Paid->value)->count(),
        OutboxEvent::query()->where('type', 'PayoutExecuted')->count(),
    ]);

    expect($payoutCount)->toBe(1)
        ->and($paidCount)->toBe(1)
        ->and($eventCount)->toBe(1);
});
