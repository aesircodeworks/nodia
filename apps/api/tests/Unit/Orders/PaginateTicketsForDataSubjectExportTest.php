<?php

use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Orders\Actions\GetTicketSaleFacts;
use App\Orders\Actions\PaginateTicketsForDataSubjectExport;
use App\Orders\Data\TicketExportRowData;
use App\Orders\Enums\OrderStatus;
use App\Orders\Models\Order;
use App\Orders\Models\OrderItem;
use App\Orders\Models\Ticket;
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

    [$this->tenantId, $this->eventId, $this->ticketTypeId] = (function (): array {
        $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

        return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): array {
            $event = Event::factory()->create(['tenant_id' => $tenantId]);
            $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);

            return [$tenantId, $event->id, $ticketType->id];
        });
    })();
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        foreach (['tickets', 'order_items', 'orders', 'ticket_types', 'customers', 'events'] as $table) {
            DB::table($table)->where('tenant_id', $this->tenantId)->delete();
        }
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKey($this->tenantId)->delete();
    });
});

/**
 * @return array{customerId: string, orderId: string}
 */
function dataSubjectTicketOrderWithItem(string $tenantId, string $eventId, string $ticketTypeId): array
{
    $customer = Customer::factory()->create(['tenant_id' => $tenantId]);
    $order = Order::factory()->create([
        'tenant_id' => $tenantId,
        'customer_id' => $customer->id,
        'event_id' => $eventId,
        'status' => OrderStatus::Paid,
    ]);

    OrderItem::factory()->create([
        'tenant_id' => $tenantId,
        'order_id' => $order->id,
        'ticket_type_id' => $ticketTypeId,
    ]);

    return ['customerId' => $customer->id, 'orderId' => $order->id];
}

it('returns rows only for the given customer\'s own orders', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        ['customerId' => $customerId, 'orderId' => $order] = dataSubjectTicketOrderWithItem(
            $this->tenantId,
            $this->eventId,
            $this->ticketTypeId,
        );

        $matching = Ticket::factory()->create([
            'tenant_id' => $this->tenantId,
            'order_id' => $order,
            'ticket_type_id' => $this->ticketTypeId,
            'event_id' => $this->eventId,
        ]);

        ['orderId' => $otherOrder] = dataSubjectTicketOrderWithItem($this->tenantId, $this->eventId, $this->ticketTypeId);

        Ticket::factory()->create([
            'tenant_id' => $this->tenantId,
            'order_id' => $otherOrder,
            'ticket_type_id' => $this->ticketTypeId,
            'event_id' => $this->eventId,
        ]);

        $paginate = new PaginateTicketsForDataSubjectExport(new GetTicketSaleFacts);
        $rows = collect($paginate($customerId))->flatten(1);

        expect($rows)->toHaveCount(1)
            ->and($rows->first())->toBeInstanceOf(TicketExportRowData::class)
            ->and($rows->first()->id)->toBe($matching->id)
            ->and($rows->first()->listPrice->amount)->toBe(5000)
            ->and($rows->first()->listPrice->currency)->toBe('USD');
    });
});

it('pages across a boundary in ascending id order, covering every row exactly once', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        ['customerId' => $customerId, 'orderId' => $order] = dataSubjectTicketOrderWithItem(
            $this->tenantId,
            $this->eventId,
            $this->ticketTypeId,
        );

        $tickets = Ticket::factory()->count(5)->create([
            'tenant_id' => $this->tenantId,
            'order_id' => $order,
            'ticket_type_id' => $this->ticketTypeId,
            'event_id' => $this->eventId,
        ]);

        $paginate = new PaginateTicketsForDataSubjectExport(new GetTicketSaleFacts, perPage: 2);
        $pages = iterator_to_array($paginate($customerId));

        expect($pages)->toHaveCount(3)
            ->and(array_map(count(...), $pages))->toBe([2, 2, 1]);

        $ids = collect($pages)->flatten(1)->pluck('id')->all();

        expect($ids)->toBe($tickets->pluck('id')->sort()->values()->all());
    });
});
