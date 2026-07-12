<?php

use App\Payments\Actions\ReconcilePayouts;
use App\Payments\Enums\PayoutStatus;
use App\Payments\Enums\SubmerchantStatus;
use App\Payments\Gateways\FakeGatewayScenarios;
use App\Payments\Gateways\GatewayPayoutRecord;
use App\Payments\Models\Payout;
use App\Support\Audit\Models\ActivityLogEntry;
use App\Support\Money\Money;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-08c plan, Slice 6: ReconcilePayouts, the poller-as-backstop for
 * missed payout webhooks (system-design 13). Polls
 * GatewayAdapter::listPayouts, creates any payout the mirror never
 * received a webhook for, stamps reconciled_at on every payout it
 * checks, and flags an amount divergence from the mirror with
 * discrepancy_amount plus an activity log entry.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(['enabled_gateways' => ['fake']])->id,
    );

    $this->accountReference = 'fakesm_reconcile_'.$this->tenantId;

    seededSubmerchantAccount($this->tenantId, [
        'gateway' => 'fake',
        'status' => SubmerchantStatus::Active,
        'gateway_account_reference' => $this->accountReference,
    ]);
});

afterEach(function (): void {
    app(FakeGatewayScenarios::class)->scriptPayouts([]);

    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        // activity_log is append-only (no DELETE granted to any role,
        // mirroring WebhookProcessingTest's precedent), so it is
        // deliberately excluded from this cleanup.
        foreach (['payouts', 'submerchant_accounts', 'outbox_deliveries', 'outbox_events'] as $table) {
            DB::table($table)->where('tenant_id', $this->tenantId)->delete();
        }
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKey($this->tenantId)->delete();
    });
});

it('creates a payout missed by every webhook and stamps reconciled_at', function (): void {
    app(FakeGatewayScenarios::class)->scriptPayouts([
        new GatewayPayoutRecord($this->accountReference, 'fake_po_missed', Money::of(7500, 'USD'), PayoutStatus::Paid, CarbonImmutable::parse('2026-07-10T00:00:00Z')),
    ]);

    $resolved = app(ReconcilePayouts::class)();

    expect($resolved)->toBe(1);

    $payout = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Payout::query()->where('gateway_reference', 'fake_po_missed')->firstOrFail(),
    );

    expect($payout->status)->toBe(PayoutStatus::Paid)
        ->and($payout->money)->toEqual(Money::of(7500, 'USD'))
        ->and($payout->reconciled_at)->not->toBeNull()
        ->and($payout->discrepancy_amount)->toBeNull();

    $eventCount = app(TenantTransaction::class)->asPlatform(
        fn () => OutboxEvent::query()->where('type', 'PayoutExecuted')->where('aggregate_id', $payout->id)->count(),
    );

    expect($eventCount)->toBe(1);
});

it('stamps reconciled_at on a payout already mirrored by a webhook with no discrepancy', function (): void {
    $mirrored = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Payout::factory()->create([
            'tenant_id' => $this->tenantId,
            'gateway_reference' => 'fake_po_matching',
            'money' => Money::of(3000, 'USD'),
            'status' => PayoutStatus::Paid,
        ]),
    );

    app(FakeGatewayScenarios::class)->scriptPayouts([
        new GatewayPayoutRecord($this->accountReference, 'fake_po_matching', Money::of(3000, 'USD'), PayoutStatus::Paid, CarbonImmutable::now()),
    ]);

    app(ReconcilePayouts::class)();

    $fresh = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Payout::query()->whereKey($mirrored->id)->firstOrFail(),
    );

    expect($fresh->reconciled_at)->not->toBeNull()
        ->and($fresh->discrepancy_amount)->toBeNull();
});

it('flags a discrepancy and writes an activity log entry when the gateway amount diverges from the mirror', function (): void {
    $mirrored = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Payout::factory()->create([
            'tenant_id' => $this->tenantId,
            'gateway_reference' => 'fake_po_diverging',
            'money' => Money::of(5000, 'USD'),
            'status' => PayoutStatus::Paid,
        ]),
    );

    app(FakeGatewayScenarios::class)->scriptPayouts([
        new GatewayPayoutRecord($this->accountReference, 'fake_po_diverging', Money::of(4800, 'USD'), PayoutStatus::Paid, CarbonImmutable::now()),
    ]);

    app(ReconcilePayouts::class)();

    $fresh = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Payout::query()->whereKey($mirrored->id)->firstOrFail(),
    );

    expect($fresh->reconciled_at)->not->toBeNull()
        ->and($fresh->discrepancy_amount)->toBe(200);

    $entry = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => ActivityLogEntry::query()
            ->where('event', 'payout_discrepancy')
            ->where('properties->payout_id', $mirrored->id)
            ->first(),
    );

    expect($entry)->not->toBeNull()
        ->and($entry->properties['discrepancy_amount'])->toBe(200);
});

it('flags a discrepancy when the gateway amount matches in minor units but differs in currency', function (): void {
    $mirrored = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Payout::factory()->create([
            'tenant_id' => $this->tenantId,
            'gateway_reference' => 'fake_po_currency',
            'money' => Money::of(5000, 'USD'),
            'status' => PayoutStatus::Paid,
        ]),
    );

    app(FakeGatewayScenarios::class)->scriptPayouts([
        new GatewayPayoutRecord($this->accountReference, 'fake_po_currency', Money::of(5000, 'EUR'), PayoutStatus::Paid, CarbonImmutable::now()),
    ]);

    app(ReconcilePayouts::class)();

    $fresh = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Payout::query()->whereKey($mirrored->id)->firstOrFail(),
    );

    expect($fresh->discrepancy_amount)->not->toBeNull();

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => ActivityLogEntry::query()
            ->where('event', 'payout_discrepancy')
            ->where('properties->payout_id', $mirrored->id)
            ->count(),
    );

    expect($count)->toBe(1);
});

it('does not flag a discrepancy or log an entry when the gateway record matches the mirror exactly', function (): void {
    $mirrored = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Payout::factory()->create([
            'tenant_id' => $this->tenantId,
            'gateway_reference' => 'fake_po_clean',
            'money' => Money::of(5000, 'USD'),
            'status' => PayoutStatus::Paid,
        ]),
    );

    app(FakeGatewayScenarios::class)->scriptPayouts([
        new GatewayPayoutRecord($this->accountReference, 'fake_po_clean', Money::of(5000, 'USD'), PayoutStatus::Paid, CarbonImmutable::now()),
    ]);

    app(ReconcilePayouts::class)();

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => ActivityLogEntry::query()->where('event', 'payout_discrepancy')->count(),
    );

    expect($count)->toBe(0);
});

it('ignores a listed payout whose account reference matches no sub-merchant account', function (): void {
    app(FakeGatewayScenarios::class)->scriptPayouts([
        new GatewayPayoutRecord('fakesm_unknown', 'fake_po_orphan', Money::of(1000, 'USD'), PayoutStatus::Paid, CarbonImmutable::now()),
    ]);

    $resolved = app(ReconcilePayouts::class)();

    expect($resolved)->toBe(0);

    $exists = app(TenantTransaction::class)->asPlatform(
        fn () => DB::table('payouts')->where('gateway_reference', 'fake_po_orphan')->exists(),
    );

    expect($exists)->toBeFalse();
});
