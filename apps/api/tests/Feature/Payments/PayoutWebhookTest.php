<?php

use App\Payments\Enums\PayoutStatus;
use App\Payments\Enums\SubmerchantStatus;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Jobs\ProcessGatewayWebhook;
use App\Payments\Models\Payout;
use App\Support\Audit\Models\ActivityLogEntry;
use App\Support\Money\Money;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-08c plan, Slice 5: the payouts mirror driven entirely by
 * gateway webhooks. The webhook carries no tenant context; the tenant
 * is resolved by looking up the sub-merchant account by (gateway,
 * gateway_account_reference) under the platform role, mirroring the
 * stage-08c sub-merchant webhook path.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    Cache::flush();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(['enabled_gateways' => ['fake']])->id,
    );

    seededSubmerchantAccount($this->tenantId, [
        'gateway' => 'fake',
        'status' => SubmerchantStatus::Active,
        'gateway_account_reference' => 'fakesm_payout_tenant',
    ]);
});

afterEach(function (): void {
    Cache::flush();

    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        foreach (['payouts', 'submerchant_accounts', 'outbox_deliveries', 'outbox_events', 'memberships', 'roles'] as $table) {
            DB::table($table)->where('tenant_id', $this->tenantId)->delete();
        }
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('gateway_webhook_events')->delete();
        Tenant::query()->whereKey($this->tenantId)->delete();
    });
});

it('creates a pending payout row from a payout created webhook', function (): void {
    deliverWebhook(app(FakeGateway::class)->payoutCreatedWebhook('fakesm_payout_tenant', 'fake_po_1', Money::of(5000, 'USD')))
        ->assertStatus(200);

    $payout = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Payout::query()->where('gateway_reference', 'fake_po_1')->first(),
    );

    expect($payout)->not->toBeNull()
        ->and($payout->status)->toBe(PayoutStatus::Pending)
        ->and($payout->money)->toEqual(Money::of(5000, 'USD'));
});

it('transitions created then paid, sets executed_at, and records PayoutExecuted', function (): void {
    deliverWebhook(app(FakeGateway::class)->payoutCreatedWebhook('fakesm_payout_tenant', 'fake_po_1', Money::of(5000, 'USD')))
        ->assertStatus(200);

    $executedAt = CarbonImmutable::parse('2026-07-12T10:00:00Z');

    deliverWebhook(app(FakeGateway::class)->payoutStatusWebhook('fakesm_payout_tenant', 'fake_po_1', PayoutStatus::Paid, $executedAt))
        ->assertStatus(200);

    $payout = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Payout::query()->where('gateway_reference', 'fake_po_1')->firstOrFail(),
    );

    expect($payout->status)->toBe(PayoutStatus::Paid)
        ->and($payout->executed_at->equalTo($executedAt))->toBeTrue();

    $eventCount = app(TenantTransaction::class)->asPlatform(
        fn () => OutboxEvent::query()->where('type', 'PayoutExecuted')->where('aggregate_id', $payout->id)->count(),
    );

    expect($eventCount)->toBe(1);
});

it('lands paid when the paid webhook arrives before in_transit', function (): void {
    deliverWebhook(app(FakeGateway::class)->payoutCreatedWebhook('fakesm_payout_tenant', 'fake_po_1', Money::of(5000, 'USD')))
        ->assertStatus(200);

    deliverWebhook(app(FakeGateway::class)->payoutStatusWebhook('fakesm_payout_tenant', 'fake_po_1', PayoutStatus::Paid))
        ->assertStatus(200);

    $payout = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Payout::query()->where('gateway_reference', 'fake_po_1')->firstOrFail(),
    );

    expect($payout->status)->toBe(PayoutStatus::Paid);

    // The stale in_transit that would ordinarily precede paid arrives
    // late and affects zero rows.
    deliverWebhook(app(FakeGateway::class)->payoutStatusWebhook('fakesm_payout_tenant', 'fake_po_1', PayoutStatus::InTransit))
        ->assertStatus(200);

    $fresh = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Payout::query()->where('gateway_reference', 'fake_po_1')->firstOrFail(),
    );

    expect($fresh->status)->toBe(PayoutStatus::Paid);
});

it('transitions to failed and records no PayoutExecuted event', function (): void {
    deliverWebhook(app(FakeGateway::class)->payoutCreatedWebhook('fakesm_payout_tenant', 'fake_po_1', Money::of(5000, 'USD')))
        ->assertStatus(200);

    deliverWebhook(app(FakeGateway::class)->payoutStatusWebhook('fakesm_payout_tenant', 'fake_po_1', PayoutStatus::Failed))
        ->assertStatus(200);

    $payout = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Payout::query()->where('gateway_reference', 'fake_po_1')->firstOrFail(),
    );

    expect($payout->status)->toBe(PayoutStatus::Failed);

    $eventCount = app(TenantTransaction::class)->asPlatform(
        fn () => OutboxEvent::query()->where('type', 'PayoutExecuted')->count(),
    );

    expect($eventCount)->toBe(0);
});

it('transitions to canceled and records no PayoutExecuted event', function (): void {
    deliverWebhook(app(FakeGateway::class)->payoutCreatedWebhook('fakesm_payout_tenant', 'fake_po_1', Money::of(5000, 'USD')))
        ->assertStatus(200);

    deliverWebhook(app(FakeGateway::class)->payoutStatusWebhook('fakesm_payout_tenant', 'fake_po_1', PayoutStatus::Canceled))
        ->assertStatus(200);

    $payout = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Payout::query()->where('gateway_reference', 'fake_po_1')->firstOrFail(),
    );

    expect($payout->status)->toBe(PayoutStatus::Canceled);

    $eventCount = app(TenantTransaction::class)->asPlatform(
        fn () => OutboxEvent::query()->where('type', 'PayoutExecuted')->count(),
    );

    expect($eventCount)->toBe(0);
});

it('logs one platform_role_use activity entry for the payout webhook tenant resolution', function (): void {
    deliverWebhook(app(FakeGateway::class)->payoutCreatedWebhook('fakesm_payout_tenant', 'fake_po_1', Money::of(5000, 'USD')))
        ->assertStatus(200);

    $count = app(TenantTransaction::class)->asPlatform(
        fn () => ActivityLogEntry::query()
            ->where('event', 'platform_role_use')
            ->where('properties->gateway_event_id', DB::table('gateway_webhook_events')->value('gateway_event_id'))
            ->count(),
    );

    expect($count)->toBe(1);
});

it('produces one row, one transition, and one PayoutExecuted event under duplicate delivery', function (): void {
    deliverWebhook(app(FakeGateway::class)->payoutCreatedWebhook('fakesm_payout_tenant', 'fake_po_1', Money::of(5000, 'USD')))
        ->assertStatus(200);

    $delivery = app(FakeGateway::class)->payoutStatusWebhook('fakesm_payout_tenant', 'fake_po_1', PayoutStatus::Paid, eventId: 'evt_payout_dup');

    foreach (range(1, 3) as $ignored) {
        deliverWebhook($delivery)->assertStatus(200);
    }

    $rowId = app(TenantTransaction::class)->asPlatform(
        fn () => DB::table('gateway_webhook_events')->where('gateway_event_id', 'evt_payout_dup')->value('id'),
    );

    // Re-running the job explicitly on top of the queued duplicates
    // proves the guard is the row's own status, not queue dedup.
    (new ProcessGatewayWebhook((string) $rowId))->handle();

    [$payoutCount, $paidCount, $eventCount] = app(TenantTransaction::class)->asPlatform(fn (): array => [
        DB::table('payouts')->where('gateway_reference', 'fake_po_1')->count(),
        DB::table('payouts')->where('gateway_reference', 'fake_po_1')->where('status', 'paid')->count(),
        OutboxEvent::query()->where('type', 'PayoutExecuted')->count(),
    ]);

    expect($payoutCount)->toBe(1)
        ->and($paidCount)->toBe(1)
        ->and($eventCount)->toBe(1);
});

it('ignores a payout webhook for an unmatched gateway account reference', function (): void {
    deliverWebhook(app(FakeGateway::class)->payoutCreatedWebhook('fakesm_unknown', 'fake_po_unknown', Money::of(5000, 'USD')))
        ->assertStatus(200);

    $row = app(TenantTransaction::class)->asPlatform(
        fn () => DB::table('gateway_webhook_events')->first(),
    );

    expect($row->status)->toBe('ignored');
});
