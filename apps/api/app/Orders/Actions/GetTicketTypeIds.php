<?php

namespace App\Orders\Actions;

use App\Orders\Models\Ticket;
use Illuminate\Support\Collection;

/**
 * Bulk ticket ID to ticket type ID lookup (stage-11 plan, task 11):
 * neither TicketCheckedInPayload nor DuplicateScanDetectedPayload
 * carries the ticket's type, only ticket_id (event_id rides along on
 * both payloads already), so App\Reporting\Jobs\ProjectEventAttendance
 * resolves it here rather than CheckIn or Reporting touching the
 * tickets table directly (event-conventions: consumers needing more
 * load it through the owning context's Actions). Deliberately narrower
 * than GetTicketSaleFacts: attendance needs no price, so this lookup
 * never joins order_items and never misses a ticket whose order lacks a
 * matching order_item. A ticket ID with no matching ticket row is
 * simply absent from the returned collection; the caller decides how to
 * treat a miss.
 */
final class GetTicketTypeIds
{
    /**
     * @param  list<string>  $ticketIds
     * @return Collection<string, string> ticket_type_id keyed by ticket_id
     */
    public function __invoke(array $ticketIds): Collection
    {
        if ($ticketIds === []) {
            return collect();
        }

        return Ticket::query()->whereIn('id', $ticketIds)->pluck('ticket_type_id', 'id');
    }
}
