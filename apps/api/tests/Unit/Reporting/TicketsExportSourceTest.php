<?php

use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Orders\Actions\GetTicketSaleFacts;
use App\Orders\Actions\PaginateTicketsForExport;
use App\Orders\Data\TicketExportRowData;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\TicketStatus;
use App\Orders\Models\Order;
use App\Orders\Models\Ticket;
use App\Reporting\Support\Export\Sources\TicketsExportSource;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-11 plan, task 15/T12: "one unit test per source in
 * tests/Unit/Reporting/ mapping Data objects to CSV columns and proving
 * the cursor iterator pages rather than loading the whole set."
 */

it('maps ticket export rows to their CSV columns, splitting the list price into amount and currency', function (): void {
    $source = new TicketsExportSource(new PaginateTicketsForExport(new GetTicketSaleFacts));

    $withPrice = new TicketExportRowData(
        id: 'ticket-1',
        eventId: 'event-1',
        ticketTypeId: 'ticket-type-1',
        orderId: 'order-1',
        status: 'issued',
        attendeeName: 'Jane Doe',
        listPrice: Money::of(2_599, 'USD'),
        issuedAt: '2026-07-10T12:00:00Z',
    );

    $columns = $source->columns();

    expect(array_map(fn (callable $extract) => $extract($withPrice), $columns))->toBe([
        'id' => 'ticket-1',
        'event_id' => 'event-1',
        'ticket_type_id' => 'ticket-type-1',
        'order_id' => 'order-1',
        'status' => 'issued',
        'attendee_name' => 'Jane Doe',
        'list_price_amount' => 2_599,
        'list_price_currency' => 'USD',
        'issued_at' => '2026-07-10T12:00:00Z',
    ]);

    $missingPrice = new TicketExportRowData(
        id: 'ticket-2',
        eventId: 'event-1',
        ticketTypeId: 'ticket-type-1',
        orderId: 'order-2',
        status: 'issued',
        attendeeName: null,
        listPrice: null,
        issuedAt: '2026-07-10T12:05:00Z',
    );

    expect(array_map(fn (callable $extract) => $extract($missingPrice), $columns))->toBe([
        'id' => 'ticket-2',
        'event_id' => 'event-1',
        'ticket_type_id' => 'ticket-type-1',
        'order_id' => 'order-2',
        'status' => 'issued',
        'attendee_name' => null,
        'list_price_amount' => null,
        'list_price_currency' => null,
        'issued_at' => '2026-07-10T12:05:00Z',
    ]);
});

it('pages tickets through PaginateTicketsForExport without loading the whole result set', function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
        $event = Event::factory()->create(['tenant_id' => $tenantId]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);
        $customer = Customer::factory()->create(['tenant_id' => $tenantId]);
        $order = Order::factory()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $customer->id,
            'event_id' => $event->id,
            'status' => OrderStatus::Paid,
        ]);

        Ticket::factory()->count(5)->create([
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'ticket_type_id' => $ticketType->id,
            'event_id' => $event->id,
            'status' => TicketStatus::Issued,
        ]);

        $source = new TicketsExportSource(new PaginateTicketsForExport(new GetTicketSaleFacts, perPage: 2));

        DB::enableQueryLog();
        $pages = $source->pages($tenantId, ['event_id' => $event->id]);

        expect(DB::getQueryLog())->toHaveCount(0);

        // Each page issues 3 queries: the cursor-paginated ticket page
        // itself, plus GetTicketSaleFacts's own two bulk lookups
        // (tickets, then order_items), proving every page's cost is
        // paid only when that page is actually requested, never upfront
        // for the whole result set.
        $pages->current();
        expect(DB::getQueryLog())->toHaveCount(3)
            ->and($pages->current())->toHaveCount(2);

        $pages->next();
        expect(DB::getQueryLog())->toHaveCount(6)
            ->and($pages->current())->toHaveCount(2);

        $pages->next();
        expect(DB::getQueryLog())->toHaveCount(9)
            ->and($pages->current())->toHaveCount(1);

        $pages->next();
        expect(DB::getQueryLog())->toHaveCount(9)
            ->and($pages->valid())->toBeFalse();
    });

    app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
        foreach (['tickets', 'orders', 'ticket_types', 'customers', 'events'] as $table) {
            DB::table($table)->where('tenant_id', $tenantId)->delete();
        }
    });

    app(TenantTransaction::class)->asPlatform(function () use ($tenantId): void {
        Tenant::query()->whereKey($tenantId)->delete();
    });
});
