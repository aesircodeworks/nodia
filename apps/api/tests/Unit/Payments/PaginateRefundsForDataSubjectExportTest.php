<?php

use App\EventCatalog\Models\Event;
use App\Identity\Models\Customer;
use App\Orders\Enums\OrderStatus;
use App\Orders\Models\Order;
use App\Payments\Actions\PaginateRefundsForDataSubjectExport;
use App\Payments\Data\RefundExportRowData;
use App\Payments\Models\Payment;
use App\Payments\Models\Refund;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-12 plan, Slice 2 Unit: "sources are cursor-paginated per
 * api-conventions' high-volume rule." Refunds carries no order_id or
 * customer_id column, only payment_id, so the caller (App\Identity\
 * Actions\BuildDataSubjectExport) always passes in a list of order IDs
 * already resolved through Orders, and this Action resolves payment IDs
 * from them internally, entirely within Payments' own tables.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        foreach (['refunds', 'payments', 'orders', 'customers', 'events'] as $table) {
            DB::table($table)->where('tenant_id', $this->tenantId)->delete();
        }
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKey($this->tenantId)->delete();
    });
});

function dataSubjectRefundOrder(string $tenantId): Order
{
    $event = Event::factory()->create(['tenant_id' => $tenantId]);
    $customer = Customer::factory()->create(['tenant_id' => $tenantId]);

    return Order::factory()->create([
        'tenant_id' => $tenantId,
        'customer_id' => $customer->id,
        'event_id' => $event->id,
        'status' => OrderStatus::Paid,
    ]);
}

it('returns an empty generator for an empty order ID list without querying', function (): void {
    $paginate = new PaginateRefundsForDataSubjectExport;

    expect(iterator_to_array($paginate([])))->toBe([]);
});

it('returns rows only for payments on the given order IDs, never another order\'s refund', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        $order = dataSubjectRefundOrder($this->tenantId);
        $otherOrder = dataSubjectRefundOrder($this->tenantId);

        $payment = Payment::factory()->create(['tenant_id' => $this->tenantId, 'order_id' => $order->id]);
        $otherPayment = Payment::factory()->create(['tenant_id' => $this->tenantId, 'order_id' => $otherOrder->id]);

        $matching = Refund::factory()->create([
            'tenant_id' => $this->tenantId,
            'payment_id' => $payment->id,
            'money' => Money::of(1500, 'USD'),
            'reason' => 'customer_request',
        ]);

        Refund::factory()->create([
            'tenant_id' => $this->tenantId,
            'payment_id' => $otherPayment->id,
        ]);

        $paginate = new PaginateRefundsForDataSubjectExport;
        $rows = collect($paginate([$order->id]))->flatten(1);

        expect($rows)->toHaveCount(1);

        $result = $rows->first();

        expect($result)->toBeInstanceOf(RefundExportRowData::class)
            ->and($result->id)->toBe($matching->id)
            ->and($result->paymentId)->toBe($payment->id)
            ->and($result->status)->toBe('pending')
            ->and($result->amount->amount)->toBe(1500)
            ->and($result->amount->currency)->toBe('USD')
            ->and($result->reason)->toBe('customer_request');
    });
});

it('pages across a boundary in ascending id order, covering every row exactly once', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        $order = dataSubjectRefundOrder($this->tenantId);
        $payment = Payment::factory()->create(['tenant_id' => $this->tenantId, 'order_id' => $order->id]);

        $refunds = Refund::factory()->count(5)->create([
            'tenant_id' => $this->tenantId,
            'payment_id' => $payment->id,
        ]);

        $paginate = new PaginateRefundsForDataSubjectExport(perPage: 2);
        $pages = iterator_to_array($paginate([$order->id]));

        expect($pages)->toHaveCount(3)
            ->and(array_map(count(...), $pages))->toBe([2, 2, 1]);

        $ids = collect($pages)->flatten(1)->pluck('id')->all();

        expect($ids)->toBe($refunds->pluck('id')->sort()->values()->all());
    });
});
