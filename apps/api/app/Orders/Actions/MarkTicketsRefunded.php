<?php

namespace App\Orders\Actions;

use App\Orders\Enums\TicketStatus;
use App\Orders\Events\TicketRefunded;
use App\Orders\Events\TicketRefundedPayload;
use App\Orders\Models\Ticket;
use App\Support\Outbox\OutboxRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * Voids tickets on refund completion, recording one TicketRefunded per
 * voided ticket (system-design 9.3 group 4; stage-08b plan, task 10).
 * Each void is its own conditional UPDATE from issued, so a duplicate
 * pass affects zero rows and records nothing, which is what makes the
 * completion consumer's void step idempotent. A null selection voids
 * every issued ticket of the order (the full-refund rule). Must run
 * inside the caller's transaction so the voids and their events commit
 * or roll back together.
 */
final class MarkTicketsRefunded
{
    public function __construct(private readonly OutboxRecorder $outbox) {}

    /**
     * @param  list<string>|null  $ticketIds
     * @return int Number of tickets voided by this pass
     */
    public function __invoke(string $orderId, ?array $ticketIds, string $refundId): int
    {
        $query = Ticket::query()
            ->where('order_id', $orderId)
            ->where('status', TicketStatus::Issued);

        if ($ticketIds !== null) {
            $query->whereIn('id', $ticketIds);
        }

        $voided = 0;

        foreach ($query->get() as $ticket) {
            $affected = Ticket::query()
                ->whereKey($ticket->id)
                ->where('status', TicketStatus::Issued)
                ->update(['status' => TicketStatus::Refunded]);

            if ($affected !== 1) {
                continue;
            }

            $voided++;

            $this->outbox->record(new TicketRefunded(
                $ticket->tenant_id,
                $ticket->id,
                new TicketRefundedPayload(
                    ticketId: $ticket->id,
                    orderId: $ticket->order_id,
                    ticketTypeId: $ticket->ticket_type_id,
                    eventId: $ticket->event_id,
                    eventSeatId: $ticket->event_seat_id,
                    refundedAt: CarbonImmutable::instance(Date::now())->utc()->format('Y-m-d\TH:i:s\Z'),
                    refundId: $refundId,
                ),
            ));
        }

        return $voided;
    }
}
