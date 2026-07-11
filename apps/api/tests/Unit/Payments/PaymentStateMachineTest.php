<?php

use App\EventCatalog\Models\Event;
use App\Identity\Models\Customer;
use App\Orders\Models\Order;
use App\Payments\Actions\ConfirmPayment;
use App\Payments\Actions\ExpirePayment;
use App\Payments\Actions\FailPayment;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Models\Payment;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-08a plan, Slice 2: every legal and illegal transition edge,
 * including confirm past expires_at with the sweeper not run, which
 * must affect zero rows under the window guard; the idempotency unique
 * constraint is a constraint, not a read-then-write check.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create()->id,
    );

    $this->orderId = app(TenantTransaction::class)->asTenant($this->tenantId, function (): string {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenantId]);
        $event = Event::factory()->create(['tenant_id' => $this->tenantId]);

        return Order::factory()->create([
            'tenant_id' => $this->tenantId,
            'customer_id' => $customer->id,
            'event_id' => $event->id,
        ])->id;
    });
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('outbox_deliveries')->where('tenant_id', $this->tenantId)->delete();
        DB::table('outbox_events')->where('tenant_id', $this->tenantId)->delete();
        DB::table('payments')->where('tenant_id', $this->tenantId)->delete();
        DB::table('orders')->where('tenant_id', $this->tenantId)->delete();
        DB::table('customers')->where('tenant_id', $this->tenantId)->delete();
        DB::table('events')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKey($this->tenantId)->delete();
    });
});

function initiatedPayment(string $tenantId, string $orderId, array $overrides = []): Payment
{
    return app(TenantTransaction::class)->asTenant($tenantId, fn () => Payment::factory()->create([
        'tenant_id' => $tenantId,
        'order_id' => $orderId,
        'status' => PaymentStatus::Initiated,
        ...$overrides,
    ]));
}

it('confirms an initiated payment, persisting the fee and a zero commission in the same statement', function (): void {
    $payment = initiatedPayment($this->tenantId, $this->orderId);

    $confirmed = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(ConfirmPayment::class)($payment->id, Money::of(125, 'USD')),
    );

    expect($confirmed)->not->toBeNull()
        ->and($confirmed->status)->toBe(PaymentStatus::Confirmed)
        ->and($confirmed->fee_amount)->toBe(125)
        ->and($confirmed->commission_amount)->toBe(0)
        ->and($confirmed->confirmed_at)->not->toBeNull();
});

it('confirms an initiated payment inside its window', function (): void {
    $payment = initiatedPayment($this->tenantId, $this->orderId, ['expires_at' => now()->addMinutes(30)]);

    $confirmed = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(ConfirmPayment::class)($payment->id, Money::of(125, 'USD')),
    );

    expect($confirmed)->not->toBeNull();
});

it('affects zero rows confirming a payment past its window even before the sweeper has run', function (): void {
    $payment = initiatedPayment($this->tenantId, $this->orderId, ['expires_at' => now()->addMinutes(30)]);

    $this->travelTo(now()->addMinutes(31));

    $confirmed = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(ConfirmPayment::class)($payment->id, Money::of(125, 'USD')),
    );

    expect($confirmed)->toBeNull()
        ->and(app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Payment::query()->findOrFail($payment->id))->status)
        ->toBe(PaymentStatus::Initiated);
});

it('fails an initiated payment with the normalized failure code', function (): void {
    $payment = initiatedPayment($this->tenantId, $this->orderId);

    $failed = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(FailPayment::class)($payment->id, 'card_declined'),
    );

    expect($failed->status)->toBe(PaymentStatus::Failed)
        ->and($failed->failure_code)->toBe('card_declined')
        ->and($failed->failed_at)->not->toBeNull();
});

it('expires an initiated payment', function (): void {
    $payment = initiatedPayment($this->tenantId, $this->orderId, ['expires_at' => now()->subMinute()]);

    $expired = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(ExpirePayment::class)($payment->id),
    );

    expect($expired->status)->toBe(PaymentStatus::Expired);
});

it('treats confirmed, failed, and expired as terminal for every transition', function (): void {
    foreach ([PaymentStatus::Confirmed, PaymentStatus::Failed, PaymentStatus::Expired] as $terminal) {
        $payment = initiatedPayment($this->tenantId, $this->orderId, ['status' => $terminal]);

        $outcomes = app(TenantTransaction::class)->asTenant($this->tenantId, fn (): array => [
            app(ConfirmPayment::class)($payment->id, Money::of(125, 'USD')),
            app(FailPayment::class)($payment->id, 'card_declined'),
            app(ExpirePayment::class)($payment->id),
        ]);

        expect($outcomes)->each->toBeNull();

        $fresh = app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Payment::query()->findOrFail($payment->id));
        expect($fresh->status)->toBe($terminal);
    }
});

it('enforces the idempotency guarantee as a unique constraint, not a read-then-write check', function (): void {
    initiatedPayment($this->tenantId, $this->orderId, ['idempotency_key' => 'key-1']);

    expect(fn () => initiatedPayment($this->tenantId, $this->orderId, ['idempotency_key' => 'key-1']))
        ->toThrow(QueryException::class, 'payments_tenant_id_idempotency_key_unique');
});

it('resolves exactly one payment per gateway reference through a partial unique index', function (): void {
    initiatedPayment($this->tenantId, $this->orderId, ['gateway_reference' => 'fake_ref-1']);
    initiatedPayment($this->tenantId, $this->orderId, ['gateway_reference' => null]);
    initiatedPayment($this->tenantId, $this->orderId, ['gateway_reference' => null]);

    expect(fn () => initiatedPayment($this->tenantId, $this->orderId, ['gateway_reference' => 'fake_ref-1']))
        ->toThrow(QueryException::class, 'payments_gateway_reference_idx');
});
