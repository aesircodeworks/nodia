<?php

namespace App\Orders\Actions;

use App\Inventory\Actions\ResolveHoldForOrder;
use App\Orders\Enums\TicketStatus;
use App\Orders\Events\TicketIssued;
use App\Orders\Models\Order;
use App\Orders\Models\Ticket;
use App\Support\Outbox\OutboxRecorder;
use Illuminate\Support\Facades\Date;
use RuntimeException;

/**
 * Creates one ticket per unit of quantity from the order_items
 * snapshots and records one TicketIssued per ticket, all inside the
 * caller's paid-transition transaction (stage-07 plan, Slice 3;
 * system-design 7.1). Seat assignment comes from the hold's claimed
 * event seats through Inventory's ResolveHoldForOrder seam: the seats
 * keep their hold_id through CommitHold, so the read works on either
 * side of the commit. Attendee names come from the order item snapshot,
 * positionally, one name per unit.
 */
final class IssueTickets
{
    public function __construct(
        private readonly OutboxRecorder $outbox,
        private readonly ResolveHoldForOrder $resolveHold,
    ) {}

    /**
     * @return list<Ticket>
     */
    public function __invoke(Order $order): array
    {
        $hold = ($this->resolveHold)($order->hold_id)
            ?? throw new RuntimeException(sprintf('Order "%s" references a hold that no longer exists.', $order->id));

        $seatsByTicketType = [];

        foreach ($hold->items as $item) {
            $seatsByTicketType[$item->ticketTypeId] = $item->seatIds;
        }

        $issuedAt = Date::now();
        $tickets = [];

        foreach ($order->items as $item) {
            $names = $item->attendee_names ?? [];
            $seats = $seatsByTicketType[$item->ticket_type_id] ?? [];

            for ($unit = 0; $unit < $item->quantity; $unit++) {
                $ticket = Ticket::query()->create([
                    'tenant_id' => $order->tenant_id,
                    'order_id' => $order->id,
                    'ticket_type_id' => $item->ticket_type_id,
                    'event_id' => $order->event_id,
                    'event_seat_id' => $seats[$unit] ?? null,
                    'status' => TicketStatus::Issued,
                    'attendee_name' => $names[$unit] ?? null,
                    'issued_at' => $issuedAt,
                    'qr_rotation_counter' => 0,
                ]);

                $this->outbox->record(TicketIssued::fromTicket($ticket));

                $tickets[] = $ticket;
            }
        }

        return $tickets;
    }
}
