<?php

use App\Payments\Actions\RecordGatewayPayout;
use App\Payments\Enums\PayoutStatus;
use App\Payments\Gateways\NormalizedPayoutEvent;
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
 * Stage-08c plan, Slice 5 unit loop: RecordGatewayPayout upserts by
 * (gateway, gateway_reference); every transition is a conditional
 * UPDATE checked by affected-row count; PayoutExecuted is recorded in
 * the same transaction as the transition to paid, and only on that
 * transition.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(['enabled_gateways' => ['fake']])->id,
    );
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('outbox_deliveries')->where('tenant_id', $this->tenantId)->delete();
        DB::table('outbox_events')->where('tenant_id', $this->tenantId)->delete();
        DB::table('payouts')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKey($this->tenantId)->delete();
    });
});

function createdPayoutEvent(string $reference = 'fake_po_1', int $amount = 1500): NormalizedPayoutEvent
{
    return new NormalizedPayoutEvent('fakesm_ref', $reference, PayoutStatus::Pending, Money::of($amount, 'BRL'), null);
}

function statusPayoutEvent(PayoutStatus $status, string $reference = 'fake_po_1', ?CarbonImmutable $executedAt = null): NormalizedPayoutEvent
{
    return new NormalizedPayoutEvent('fakesm_ref', $reference, $status, null, $executedAt);
}

it('inserts a pending row from a payout.created event and records no outbox event', function (): void {
    $payout = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(RecordGatewayPayout::class)($this->tenantId, 'fake', createdPayoutEvent()),
    );

    expect($payout)->not->toBeNull()
        ->and($payout->status)->toBe(PayoutStatus::Pending)
        ->and($payout->money)->toEqual(Money::of(1500, 'BRL'));

    $eventCount = app(TenantTransaction::class)->asPlatform(
        fn () => OutboxEvent::query()->where('type', 'PayoutExecuted')->count(),
    );

    expect($eventCount)->toBe(0);
});

it('no-ops a duplicate payout.created event on an existing row', function (): void {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(RecordGatewayPayout::class)($this->tenantId, 'fake', createdPayoutEvent()),
    );

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(RecordGatewayPayout::class)($this->tenantId, 'fake', createdPayoutEvent()),
    );

    expect($result)->toBeNull();

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Payout::query()->where('gateway_reference', 'fake_po_1')->count(),
    );

    expect($count)->toBe(1);
});

it('returns null for a status-changed event with no existing row and no amount to insert', function (): void {
    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(RecordGatewayPayout::class)($this->tenantId, 'fake', statusPayoutEvent(PayoutStatus::InTransit)),
    );

    expect($result)->toBeNull();
});

it('transitions pending to in_transit', function (): void {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(RecordGatewayPayout::class)($this->tenantId, 'fake', createdPayoutEvent()),
    );

    $payout = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(RecordGatewayPayout::class)($this->tenantId, 'fake', statusPayoutEvent(PayoutStatus::InTransit)),
    );

    expect($payout->status)->toBe(PayoutStatus::InTransit);
});

it('skips forward from pending straight to paid, sets executed_at, and records PayoutExecuted', function (): void {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(RecordGatewayPayout::class)($this->tenantId, 'fake', createdPayoutEvent()),
    );

    $executedAt = CarbonImmutable::parse('2026-07-12T10:00:00Z');

    $payout = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(RecordGatewayPayout::class)($this->tenantId, 'fake', statusPayoutEvent(PayoutStatus::Paid, executedAt: $executedAt)),
    );

    expect($payout->status)->toBe(PayoutStatus::Paid)
        ->and($payout->executed_at->equalTo($executedAt))->toBeTrue();

    $event = app(TenantTransaction::class)->asPlatform(
        fn () => OutboxEvent::query()->where('type', 'PayoutExecuted')->where('aggregate_id', $payout->id)->first(),
    );

    expect($event)->not->toBeNull()
        ->and($event->payload['payout_id'])->toBe($payout->id)
        ->and($event->payload['gateway_reference'])->toBe('fake_po_1')
        ->and($event->payload['amount']['amount'])->toBe(1500)
        ->and($event->payload['amount']['currency'])->toBe('BRL');
});

it('inserts a paid payout.created with no executed_at, stamping now and recording PayoutExecuted', function (): void {
    $event = new NormalizedPayoutEvent('fakesm_ref', 'fake_po_paid', PayoutStatus::Paid, Money::of(1500, 'BRL'), null);

    $payout = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(RecordGatewayPayout::class)($this->tenantId, 'fake', $event),
    );

    expect($payout)->not->toBeNull()
        ->and($payout->status)->toBe(PayoutStatus::Paid)
        ->and($payout->executed_at)->not->toBeNull();

    $eventCount = app(TenantTransaction::class)->asPlatform(
        fn () => OutboxEvent::query()->where('type', 'PayoutExecuted')->where('aggregate_id', $payout->id)->count(),
    );

    expect($eventCount)->toBe(1);
});

it('records a payout_state_changed activity entry on insert and on transition', function (): void {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(RecordGatewayPayout::class)($this->tenantId, 'fake', createdPayoutEvent('fake_po_audit')),
    );

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(RecordGatewayPayout::class)($this->tenantId, 'fake', statusPayoutEvent(PayoutStatus::Paid, 'fake_po_audit')),
    );

    $entries = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => ActivityLogEntry::query()
            ->where('event', 'payout_state_changed')
            ->where('properties->gateway_reference', 'fake_po_audit')
            ->orderBy('created_at')
            ->pluck('properties'),
    );

    expect($entries)->toHaveCount(2)
        ->and($entries[0]['status'])->toBe(PayoutStatus::Pending->value)
        ->and($entries[1]['status'])->toBe(PayoutStatus::Paid->value);
});

it('a late in_transit after paid affects zero rows and is dropped', function (): void {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(RecordGatewayPayout::class)($this->tenantId, 'fake', createdPayoutEvent()),
    );

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(RecordGatewayPayout::class)($this->tenantId, 'fake', statusPayoutEvent(PayoutStatus::Paid)),
    );

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(RecordGatewayPayout::class)($this->tenantId, 'fake', statusPayoutEvent(PayoutStatus::InTransit)),
    );

    expect($result)->toBeNull();

    $payout = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Payout::query()->where('gateway_reference', 'fake_po_1')->firstOrFail(),
    );

    expect($payout->status)->toBe(PayoutStatus::Paid);

    $eventCount = app(TenantTransaction::class)->asPlatform(
        fn () => OutboxEvent::query()->where('type', 'PayoutExecuted')->count(),
    );

    expect($eventCount)->toBe(1);
});

it('records no domain event when a payout lands on failed', function (): void {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(RecordGatewayPayout::class)($this->tenantId, 'fake', createdPayoutEvent()),
    );

    $payout = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(RecordGatewayPayout::class)($this->tenantId, 'fake', statusPayoutEvent(PayoutStatus::Failed)),
    );

    expect($payout->status)->toBe(PayoutStatus::Failed);

    $eventCount = app(TenantTransaction::class)->asPlatform(
        fn () => OutboxEvent::query()->where('type', 'PayoutExecuted')->count(),
    );

    expect($eventCount)->toBe(0);
});

it('records no domain event when a payout lands on canceled', function (): void {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(RecordGatewayPayout::class)($this->tenantId, 'fake', createdPayoutEvent()),
    );

    $payout = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(RecordGatewayPayout::class)($this->tenantId, 'fake', statusPayoutEvent(PayoutStatus::Canceled)),
    );

    expect($payout->status)->toBe(PayoutStatus::Canceled);

    $eventCount = app(TenantTransaction::class)->asPlatform(
        fn () => OutboxEvent::query()->where('type', 'PayoutExecuted')->count(),
    );

    expect($eventCount)->toBe(0);
});

it('rejects an illegal transition from a terminal status, affecting zero rows', function (): void {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(RecordGatewayPayout::class)($this->tenantId, 'fake', createdPayoutEvent()),
    );

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(RecordGatewayPayout::class)($this->tenantId, 'fake', statusPayoutEvent(PayoutStatus::Failed)),
    );

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(RecordGatewayPayout::class)($this->tenantId, 'fake', statusPayoutEvent(PayoutStatus::InTransit)),
    );

    expect($result)->toBeNull();
});
