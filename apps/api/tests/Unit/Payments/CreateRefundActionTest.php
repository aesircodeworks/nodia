<?php

use App\EventCatalog\Models\Event;
use App\Identity\Models\Customer;
use App\Orders\Enums\OrderStatus;
use App\Orders\Models\Order;
use App\Payments\Actions\CreateRefund;
use App\Payments\Data\CreateRefundData;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Exceptions\RefundAmountExceedsRefundableException;
use App\Payments\Models\Payment;
use App\Payments\Models\Refund;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-08b plan, Slice 5 unit loop: RefundInitiated rides the creating
 * transaction (rolled back with it), and the returned commission is
 * proportional, capped by the un-returned remainder, with round half up
 * pinned at the boundary.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(['refund_commission_policy' => 'returned'])->id,
    );

    [$this->orderId, $this->paymentId] = app(TenantTransaction::class)->asTenant($this->tenantId, function (): array {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenantId]);
        $event = Event::factory()->create(['tenant_id' => $this->tenantId]);

        $order = Order::factory()->create([
            'tenant_id' => $this->tenantId,
            'customer_id' => $customer->id,
            'event_id' => $event->id,
            'status' => OrderStatus::Paid,
        ]);

        $payment = Payment::factory()->create([
            'tenant_id' => $this->tenantId,
            'order_id' => $order->id,
            'status' => PaymentStatus::Confirmed,
            'money' => Money::of(1_000, 'USD'),
        ]);

        DB::table('payments')->where('id', $payment->id)->update(['commission_amount' => 100]);

        return [$order->id, $payment->id];
    });
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        foreach (['outbox_deliveries', 'outbox_events', 'refunds', 'payments', 'orders', 'customers', 'events'] as $table) {
            DB::table($table)->where('tenant_id', $this->tenantId)->delete();
        }
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKey($this->tenantId)->delete();
    });
});

function createRefund(string $tenantId, string $paymentId, ?array $amount, ?string $key = null): Refund
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => app(CreateRefund::class)(
            $paymentId,
            CreateRefundData::from(['amount' => $amount, 'reason' => null, 'ticket_ids' => null]),
            $key ?? (string) Str::uuid7(),
        )->refund,
    );
}

it('records RefundInitiated in the creating transaction and rolls both back together', function (): void {
    $paymentId = $this->paymentId;

    try {
        app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($paymentId): void {
            app(CreateRefund::class)(
                $paymentId,
                CreateRefundData::from(['amount' => ['amount' => 400, 'currency' => 'USD']]),
                (string) Str::uuid7(),
            );

            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException) {
    }

    [$refunds, $events, $reserved] = app(TenantTransaction::class)->asTenant($this->tenantId, fn (): array => [
        DB::table('refunds')->where('payment_id', $paymentId)->count(),
        DB::table('outbox_events')->where('type', 'RefundInitiated')->count(),
        Payment::query()->findOrFail($paymentId)->refunded_amount,
    ]);

    expect($refunds)->toBe(0)
        ->and($events)->toBe(0)
        ->and($reserved)->toBe(0);
});

it('derives the proportional commission with round half up at the boundary', function (): void {
    // 100 commission * 333 / 1000 = 33.3 -> 33; * 335 / 1000 = 33.5 -> 34.
    $first = createRefund($this->tenantId, $this->paymentId, ['amount' => 333, 'currency' => 'USD']);
    $second = createRefund($this->tenantId, $this->paymentId, ['amount' => 335, 'currency' => 'USD']);

    expect($first->commission_amount)->toBe(33)
        ->and($second->commission_amount)->toBe(34);
});

it('caps the returned commission by the un-returned remainder', function (): void {
    createRefund($this->tenantId, $this->paymentId, ['amount' => 500, 'currency' => 'USD']);

    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        // Simulate an earlier over-return so the proportional share of the
        // second refund exceeds what remains returnable.
        DB::table('payments')->where('id', $this->paymentId)->update(['refunded_commission_amount' => 90]);
    });

    $second = createRefund($this->tenantId, $this->paymentId, ['amount' => 500, 'currency' => 'USD']);

    expect($second->commission_amount)->toBe(10);
});

it('rejects a reservation past the payment amount with zero rows affected, never read-then-write', function (): void {
    createRefund($this->tenantId, $this->paymentId, ['amount' => 800, 'currency' => 'USD']);

    createRefund($this->tenantId, $this->paymentId, ['amount' => 300, 'currency' => 'USD']);
})->throws(RefundAmountExceedsRefundableException::class);

it('defaults a null amount to the full remaining refundable amount', function (): void {
    createRefund($this->tenantId, $this->paymentId, ['amount' => 400, 'currency' => 'USD']);

    $rest = createRefund($this->tenantId, $this->paymentId, null);

    expect($rest->amount)->toBe(600)
        ->and($rest->commission_amount)->toBe(60);
});
