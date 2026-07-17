<?php

use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Orders\Actions\GetTicketSaleFacts;
use App\Orders\Actions\PaginateTicketsForExport;
use App\Orders\Data\TicketExportRowData;
use App\Orders\Enums\OrderStatus;
use App\Orders\Models\Order;
use App\Orders\Models\OrderItem;
use App\Orders\Models\Ticket;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-11 plan, task 15/T12: "plus unit coverage of the three new
 * owning-context Actions in their own suites." Mirrors
 * PaginateOrdersForExport's own filter matrix, over Ticket instead of
 * Order.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    [$this->tenantId, $this->eventId, $this->otherEventId, $this->ticketTypeId] = (function (): array {
        $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

        return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): array {
            $event = Event::factory()->create(['tenant_id' => $tenantId]);
            $otherEvent = Event::factory()->create(['tenant_id' => $tenantId]);
            $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);

            return [$tenantId, $event->id, $otherEvent->id, $ticketType->id];
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
 * @return array{orderId: string}
 */
function ticketExportOrderWithItem(string $tenantId, string $eventId, string $ticketTypeId): array
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

    return ['orderId' => $order->id];
}

it('filters by event_id and returns rows only for the matching event', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        $order = ticketExportOrderWithItem($this->tenantId, $this->eventId, $this->ticketTypeId)['orderId'];

        $matching = Ticket::factory()->create([
            'tenant_id' => $this->tenantId,
            'order_id' => $order,
            'ticket_type_id' => $this->ticketTypeId,
            'event_id' => $this->eventId,
        ]);

        $otherOrder = ticketExportOrderWithItem($this->tenantId, $this->otherEventId, $this->ticketTypeId)['orderId'];

        Ticket::factory()->create([
            'tenant_id' => $this->tenantId,
            'order_id' => $otherOrder,
            'ticket_type_id' => $this->ticketTypeId,
            'event_id' => $this->otherEventId,
        ]);

        $paginate = new PaginateTicketsForExport(new GetTicketSaleFacts);
        $rows = collect($paginate($this->eventId, null, null))->flatten(1);

        expect($rows)->toHaveCount(1)
            ->and($rows->first())->toBeInstanceOf(TicketExportRowData::class)
            ->and($rows->first()->id)->toBe($matching->id)
            ->and($rows->first()->listPrice->amount)->toBe(5000)
            ->and($rows->first()->listPrice->currency)->toBe('USD');
    });
});

it('bounds issued_at inclusively on both ends', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        $order = ticketExportOrderWithItem($this->tenantId, $this->eventId, $this->ticketTypeId)['orderId'];

        $inside = Ticket::factory()->create([
            'tenant_id' => $this->tenantId,
            'order_id' => $order,
            'ticket_type_id' => $this->ticketTypeId,
            'event_id' => $this->eventId,
            'issued_at' => CarbonImmutable::parse('2026-07-10T12:00:00Z'),
        ]);

        $before = Ticket::factory()->create([
            'tenant_id' => $this->tenantId,
            'order_id' => $order,
            'ticket_type_id' => $this->ticketTypeId,
            'event_id' => $this->eventId,
            'issued_at' => CarbonImmutable::parse('2026-06-01T00:00:00Z'),
        ]);

        $after = Ticket::factory()->create([
            'tenant_id' => $this->tenantId,
            'order_id' => $order,
            'ticket_type_id' => $this->ticketTypeId,
            'event_id' => $this->eventId,
            'issued_at' => CarbonImmutable::parse('2026-08-01T00:00:00Z'),
        ]);

        $paginate = new PaginateTicketsForExport(new GetTicketSaleFacts);
        $ids = collect($paginate(null, '2026-07-01T00:00:00Z', '2026-07-31T23:59:59Z'))
            ->flatten(1)
            ->pluck('id')
            ->all();

        expect($ids)->toBe([$inside->id])
            ->and($ids)->not->toContain($before->id)
            ->and($ids)->not->toContain($after->id);
    });
});

it('leaves listPrice null for a ticket whose order carries no matching order_item', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenantId]);
        $order = Order::factory()->create([
            'tenant_id' => $this->tenantId,
            'customer_id' => $customer->id,
            'event_id' => $this->eventId,
            'status' => OrderStatus::Paid,
        ]);

        $ticket = Ticket::factory()->create([
            'tenant_id' => $this->tenantId,
            'order_id' => $order->id,
            'ticket_type_id' => $this->ticketTypeId,
            'event_id' => $this->eventId,
        ]);

        $paginate = new PaginateTicketsForExport(new GetTicketSaleFacts);
        $rows = collect($paginate($this->eventId, null, null))->flatten(1);

        expect($rows)->toHaveCount(1)
            ->and($rows->first()->id)->toBe($ticket->id)
            ->and($rows->first()->listPrice)->toBeNull();
    });
});

it('pages across a boundary in ascending id order, covering every row exactly once', function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        $order = ticketExportOrderWithItem($this->tenantId, $this->eventId, $this->ticketTypeId)['orderId'];

        $tickets = Ticket::factory()->count(5)->create([
            'tenant_id' => $this->tenantId,
            'order_id' => $order,
            'ticket_type_id' => $this->ticketTypeId,
            'event_id' => $this->eventId,
        ]);

        $paginate = new PaginateTicketsForExport(new GetTicketSaleFacts, perPage: 2);
        $pages = iterator_to_array($paginate($this->eventId, null, null));

        expect($pages)->toHaveCount(3)
            ->and(array_map(count(...), $pages))->toBe([2, 2, 1]);

        $ids = collect($pages)->flatten(1)->pluck('id')->all();

        expect($ids)->toBe($tickets->pluck('id')->sort()->values()->all());
    });
});
