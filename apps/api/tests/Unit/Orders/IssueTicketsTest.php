<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\Seat;
use App\EventCatalog\Models\SeatMap;
use App\EventCatalog\Models\TicketType;
use App\EventCatalog\Models\Venue;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Enums\EventSeatStatus;
use App\Inventory\Models\EventSeat;
use App\Inventory\Models\TicketTypeInventory;
use App\Orders\Actions\ConvertHoldToOrder;
use App\Orders\Actions\IssueTickets;
use App\Orders\Data\CreateOrderData;
use App\Orders\Enums\TicketStatus;
use App\Orders\Models\Order;
use App\Orders\Models\Ticket;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-07 plan, TDD sequencing Slice 3: IssueTickets creates one
 * ticket per unit of quantity from order_items snapshots, copies
 * attendee names, assigns event_seat_id for seated items, and records
 * TicketIssued in the same transaction (asserted via transaction
 * rollback leaving no outbox rows).
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('outbox_deliveries')->where('tenant_id', $this->tenantId)->delete();
        DB::table('outbox_events')->where('tenant_id', $this->tenantId)->delete();
        DB::table('media')->where('tenant_id', $this->tenantId)->delete();
        DB::table('tickets')->where('tenant_id', $this->tenantId)->delete();
        DB::table('order_items')->where('tenant_id', $this->tenantId)->delete();
        DB::table('orders')->where('tenant_id', $this->tenantId)->delete();
        DB::table('hold_items')->where('tenant_id', $this->tenantId)->delete();
        DB::table('event_seats')->where('tenant_id', $this->tenantId)->delete();
        DB::table('holds')->where('tenant_id', $this->tenantId)->delete();
        DB::table('customers')->where('tenant_id', $this->tenantId)->delete();
        DB::table('ticket_type_inventory')->where('tenant_id', $this->tenantId)->delete();
        DB::table('ticket_types')->where('tenant_id', $this->tenantId)->delete();
        DB::table('events')->where('tenant_id', $this->tenantId)->delete();
        DB::table('seats')->where('tenant_id', $this->tenantId)->delete();
        DB::table('seat_maps')->where('tenant_id', $this->tenantId)->delete();
        DB::table('venues')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );
});

/**
 * A pending order over one GA line (quantity 2, named attendees) and,
 * when $seated, one seated line (quantity 2) with materialized
 * event_seats claimed by the hold.
 *
 * @return array{order: Order, gaTicketTypeId: string, seatedTicketTypeId: ?string, seatIds: list<string>}
 */
function issueTicketsFixture(string $tenantId, bool $seated = false): array
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $seated): array {
        $event = Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]);
        $customer = Customer::factory()->create(['tenant_id' => $tenantId, 'email' => 'issue@example.com']);

        $ga = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);
        TicketTypeInventory::factory()->create([
            'tenant_id' => $tenantId,
            'ticket_type_id' => $ga->id,
            'quantity' => 10,
            'held' => 0,
            'sold' => 0,
        ]);

        $items = [['ticket_type_id' => $ga->id, 'quantity' => 2]];
        $seatIds = [];
        $seatedTicketType = null;

        if ($seated) {
            $venue = Venue::factory()->create(['tenant_id' => $tenantId]);
            $seatMap = SeatMap::factory()->create(['tenant_id' => $tenantId, 'venue_id' => $venue->id]);
            $seatedTicketType = TicketType::factory()->create([
                'tenant_id' => $tenantId,
                'event_id' => $event->id,
                'requires_seat' => true,
            ]);

            DB::table('events')->where('id', $event->id)->update([
                'venue_id' => $venue->id,
                'seat_map_id' => $seatMap->id,
                'is_virtual' => false,
                'virtual_event_url' => null,
            ]);

            TicketTypeInventory::factory()->create([
                'tenant_id' => $tenantId,
                'ticket_type_id' => $seatedTicketType->id,
                'quantity' => 2,
                'held' => 0,
                'sold' => 0,
            ]);

            for ($i = 0; $i < 2; $i++) {
                $templateSeat = Seat::factory()->create([
                    'tenant_id' => $tenantId,
                    'seat_map_id' => $seatMap->id,
                    'section' => 'A',
                    'row' => '1',
                    'number' => (string) ($i + 1),
                ]);

                $seatIds[] = EventSeat::factory()->create([
                    'tenant_id' => $tenantId,
                    'event_id' => $event->id,
                    'seat_id' => $templateSeat->id,
                    'ticket_type_id' => $seatedTicketType->id,
                    'status' => EventSeatStatus::Available,
                ])->id;
            }

            $items[] = ['ticket_type_id' => $seatedTicketType->id, 'quantity' => 2];
        }

        $holdId = app(CreateHold::class)(
            CreateHoldData::from([
                'event_id' => $event->id,
                'items' => $items,
                ...$seated ? ['seat_ids' => $seatIds] : [],
            ]),
            $customer->id,
        )->id;

        $orderData = app(ConvertHoldToOrder::class)(
            CreateOrderData::from([
                'hold_id' => $holdId,
                'attendee_names' => [$ga->id => ['Ada Lovelace', 'Grace Hopper']],
            ]),
            $customer->id,
        );

        return [
            'order' => Order::query()->with('items')->findOrFail($orderData->id),
            'gaTicketTypeId' => $ga->id,
            'seatedTicketTypeId' => $seatedTicketType?->id,
            'seatIds' => $seatIds,
        ];
    });
}

it('creates one issued ticket per unit of quantity and copies attendee names', function (): void {
    ['order' => $order, 'gaTicketTypeId' => $gaTicketTypeId] = issueTicketsFixture($this->tenantId);

    $now = now();
    $this->travelTo($now);

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(IssueTickets::class)($order),
    );

    $tickets = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Ticket::query()->where('order_id', $order->id)->orderBy('attendee_name')->get(),
    );

    expect($tickets)->toHaveCount(2)
        ->and($tickets->pluck('attendee_name')->all())->toBe(['Ada Lovelace', 'Grace Hopper'])
        ->and($tickets->pluck('status')->unique()->all())->toBe([TicketStatus::Issued])
        ->and($tickets->pluck('ticket_type_id')->unique()->all())->toBe([$gaTicketTypeId])
        ->and($tickets->pluck('event_id')->unique()->all())->toBe([$order->event_id])
        ->and($tickets->pluck('qr_rotation_counter')->unique()->all())->toBe([0])
        ->and($tickets[0]->issued_at->utc()->toIso8601String())->toBe($now->copy()->utc()->toIso8601String());
});

it('assigns event_seat_id for seated items and leaves GA tickets seatless', function (): void {
    $fixture = issueTicketsFixture($this->tenantId, seated: true);

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(IssueTickets::class)($fixture['order']),
    );

    $tickets = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Ticket::query()->where('order_id', $fixture['order']->id)->get(),
    );

    $seatedTickets = $tickets->where('ticket_type_id', $fixture['seatedTicketTypeId']);
    $gaTickets = $tickets->where('ticket_type_id', $fixture['gaTicketTypeId']);

    expect($tickets)->toHaveCount(4)
        ->and($gaTickets->pluck('event_seat_id')->unique()->all())->toBe([null])
        ->and($seatedTickets->pluck('event_seat_id')->sort()->values()->all())
        ->toEqualCanonicalizing($fixture['seatIds']);
});

it('records one TicketIssued per ticket in the issuing transaction, gone on rollback', function (): void {
    ['order' => $order] = issueTicketsFixture($this->tenantId);

    try {
        app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($order): void {
            app(IssueTickets::class)($order);

            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException) {
    }

    $leftovers = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => [
            'tickets' => Ticket::query()->where('order_id', $order->id)->count(),
            'events' => OutboxEvent::query()->where('tenant_id', $this->tenantId)->where('type', 'TicketIssued')->count(),
        ],
    );

    expect($leftovers['tickets'])->toBe(0)->and($leftovers['events'])->toBe(0);

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(IssueTickets::class)($order),
    );

    $issued = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()
            ->where('tenant_id', $this->tenantId)
            ->where('type', 'TicketIssued')
            ->get(),
    );

    $ticketIds = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Ticket::query()->where('order_id', $order->id)->pluck('id'),
    );

    expect($issued)->toHaveCount(2)
        ->and($issued->pluck('aggregate_id')->sort()->values()->all())
        ->toEqualCanonicalizing($ticketIds->sort()->values()->all());

    foreach ($issued as $event) {
        expect($event->aggregate_type)->toBe('ticket')
            ->and($event->payload['order_id'])->toBe($order->id)
            ->and($event->payload)->toHaveKeys(['ticket_id', 'ticket_type_id', 'event_id', 'event_seat_id', 'issued_at'])
            ->and($event->payload)->not->toHaveKey('attendee_name');
    }
});
