<?php

use App\EventCatalog\Models\Event;
use App\Identity\Models\Customer;
use App\Orders\Actions\PaginateOrdersForDataSubjectExport;
use App\Orders\Data\OrderExportRowData;
use App\Orders\Enums\OrderStatus;
use App\Orders\Models\Order;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-12 plan, Slice 2 Unit: "sources are cursor-paginated per
 * api-conventions' high-volume rule." Mirrors
 * tests/Unit/Orders/PaginateTicketsForExportTest.php's own filter and
 * paging matrix, filtering by customer_id instead of event_id.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    [$this->tenantId, $this->eventId] = (function (): array {
        $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

        return app(TenantTransaction::class)->asTenant(
            $tenantId,
            fn (): array => [$tenantId, Event::factory()->create(['tenant_id' => $tenantId])->id],
        );
    })();
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        foreach (['orders', 'customers', 'events'] as $table) {
            DB::table($table)->where('tenant_id', $this->tenantId)->delete();
        }
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKey($this->tenantId)->delete();
    });
});

it('returns rows only for the given customer, never another customer in the same tenant', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenantId]);
        $otherCustomer = Customer::factory()->create(['tenant_id' => $this->tenantId]);

        $matching = Order::factory()->create([
            'tenant_id' => $this->tenantId,
            'customer_id' => $customer->id,
            'event_id' => $this->eventId,
            'status' => OrderStatus::Paid,
        ]);

        Order::factory()->create([
            'tenant_id' => $this->tenantId,
            'customer_id' => $otherCustomer->id,
            'event_id' => $this->eventId,
            'status' => OrderStatus::Paid,
        ]);

        $paginate = new PaginateOrdersForDataSubjectExport;
        $rows = collect($paginate($customer->id))->flatten(1);

        expect($rows)->toHaveCount(1)
            ->and($rows->first())->toBeInstanceOf(OrderExportRowData::class)
            ->and($rows->first()->id)->toBe($matching->id);
    });
});

it('pages across a boundary in ascending id order, covering every row exactly once', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenantId]);

        $orders = Order::factory()->count(5)->create([
            'tenant_id' => $this->tenantId,
            'customer_id' => $customer->id,
            'event_id' => $this->eventId,
            'status' => OrderStatus::Paid,
        ]);

        $paginate = new PaginateOrdersForDataSubjectExport(perPage: 2);
        $pages = iterator_to_array($paginate($customer->id));

        expect($pages)->toHaveCount(3)
            ->and(array_map(count(...), $pages))->toBe([2, 2, 1]);

        $ids = collect($pages)->flatten(1)->pluck('id')->all();

        expect($ids)->toBe($orders->pluck('id')->sort()->values()->all());
    });
});
