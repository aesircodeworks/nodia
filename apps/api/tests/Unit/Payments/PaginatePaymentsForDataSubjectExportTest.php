<?php

use App\EventCatalog\Models\Event;
use App\Identity\Models\Customer;
use App\Orders\Enums\OrderStatus;
use App\Orders\Models\Order;
use App\Payments\Actions\PaginatePaymentsForDataSubjectExport;
use App\Payments\Data\PaymentExportRowData;
use App\Payments\Models\Payment;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-12 plan, Slice 2 Unit: "sources are cursor-paginated per
 * api-conventions' high-volume rule." Payments carries no customer_id
 * column, so the caller (App\Identity\Actions\BuildDataSubjectExport)
 * always passes in a list of order IDs already resolved through Orders;
 * this suite exercises that same contract directly.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        foreach (['payments', 'orders', 'customers', 'events'] as $table) {
            DB::table($table)->where('tenant_id', $this->tenantId)->delete();
        }
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKey($this->tenantId)->delete();
    });
});

function dataSubjectPaymentOrder(string $tenantId): Order
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
    $paginate = new PaginatePaymentsForDataSubjectExport;

    expect(iterator_to_array($paginate([])))->toBe([]);
});

it('returns rows only for the given order IDs, never another order\'s payment', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        $order = dataSubjectPaymentOrder($this->tenantId);
        $otherOrder = dataSubjectPaymentOrder($this->tenantId);

        $matching = Payment::factory()->create([
            'tenant_id' => $this->tenantId,
            'order_id' => $order->id,
            'money' => Money::of(4200, 'USD'),
        ]);

        Payment::factory()->create([
            'tenant_id' => $this->tenantId,
            'order_id' => $otherOrder->id,
        ]);

        $paginate = new PaginatePaymentsForDataSubjectExport;
        $rows = collect($paginate([$order->id]))->flatten(1);

        expect($rows)->toHaveCount(1);

        $result = $rows->first();

        expect($result)->toBeInstanceOf(PaymentExportRowData::class)
            ->and($result->id)->toBe($matching->id)
            ->and($result->orderId)->toBe($order->id)
            ->and($result->gateway)->toBe('fake')
            ->and($result->method)->toBe('card')
            ->and($result->status)->toBe('initiated')
            ->and($result->amount->amount)->toBe(4200)
            ->and($result->amount->currency)->toBe('USD');
    });
});

it('pages across a boundary in ascending id order, covering every row exactly once', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        $order = dataSubjectPaymentOrder($this->tenantId);

        $payments = Payment::factory()->count(5)->create([
            'tenant_id' => $this->tenantId,
            'order_id' => $order->id,
        ]);

        $paginate = new PaginatePaymentsForDataSubjectExport(perPage: 2);
        $pages = iterator_to_array($paginate([$order->id]));

        expect($pages)->toHaveCount(3)
            ->and(array_map(count(...), $pages))->toBe([2, 2, 1]);

        $ids = collect($pages)->flatten(1)->pluck('id')->all();

        expect($ids)->toBe($payments->pluck('id')->sort()->values()->all());
    });
});
