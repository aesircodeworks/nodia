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
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-08b plan, Slice 5 concurrency rule: N parallel partial-refund
 * creations never reserve more than payments.amount; losers get
 * refund_amount_exceeds_refundable. The guard is the conditional UPDATE
 * incrementing refunded_amount within its cap, checked by affected-row
 * count against real PostgreSQL.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

it('never reserves past the payment amount under parallel partial refunds', function (): void {
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $paymentId = app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): string {
        $customer = Customer::factory()->create(['tenant_id' => $tenantId]);
        $event = Event::factory()->create(['tenant_id' => $tenantId]);
        $order = Order::factory()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $customer->id,
            'event_id' => $event->id,
            'status' => OrderStatus::Paid,
        ]);

        return Payment::factory()->create([
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'status' => PaymentStatus::Confirmed,
            'money' => Money::of(5_000, 'USD'),
            'gateway_reference' => 'fake_'.Str::uuid7(),
        ])->id;
    });

    $results = ParallelRunner::run(6, function () use ($tenantId, $paymentId): string {
        try {
            app(TenantTransaction::class)->asTenant(
                $tenantId,
                fn () => app(CreateRefund::class)(
                    $paymentId,
                    CreateRefundData::from(['amount' => ['amount' => 2_000, 'currency' => 'USD']]),
                    (string) Str::uuid7(),
                ),
            );

            return 'created';
        } catch (RefundAmountExceedsRefundableException) {
            return 'exceeded';
        }
    });

    [$payment, $refundSum, $refundCount] = app(TenantTransaction::class)->asTenant($tenantId, fn (): array => [
        Payment::query()->findOrFail($paymentId),
        (int) DB::table('refunds')->where('payment_id', $paymentId)->sum('amount'),
        DB::table('refunds')->where('payment_id', $paymentId)->count(),
    ]);

    $outcomes = array_count_values($results);

    expect($outcomes['created'] ?? 0)->toBe(2)
        ->and($outcomes['exceeded'] ?? 0)->toBe(4)
        ->and($payment->refunded_amount)->toBe(4_000)
        ->and($refundSum)->toBe(4_000)
        ->and($refundCount)->toBe(2);

    app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
        foreach (['outbox_deliveries', 'outbox_events', 'refunds', 'payments', 'orders', 'customers', 'events'] as $table) {
            DB::table($table)->where('tenant_id', $tenantId)->delete();
        }
    });

    app(TenantTransaction::class)->asPlatform(function () use ($tenantId): void {
        Tenant::query()->whereKey($tenantId)->delete();
    });
});
