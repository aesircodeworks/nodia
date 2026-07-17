<?php

namespace App\Orders\Actions;

use App\Orders\Data\TicketSaleFactsData;
use App\Orders\Models\OrderItem;
use App\Orders\Models\Ticket;
use Illuminate\Support\Collection;

/**
 * Bulk ticket ID to sale-facts lookup (stage-11 plan, Data model
 * "report_daily_sales" money semantics note; task 5). Ticket rows carry
 * no price of their own; list price at issue is the order_item unit_price
 * snapshotted at conversion time (stage-07 plan, Data model
 * "order_items"), keyed by (order_id, ticket_type_id) since one order
 * item prices every unit of one ticket type on an order. A ticket ID with
 * no matching ticket, or whose order carries no matching order_item, is
 * simply absent from the returned collection; callers decide how to
 * treat a miss.
 */
final class GetTicketSaleFacts
{
    /**
     * @param  list<string>  $ticketIds
     * @return Collection<string, TicketSaleFactsData> keyed by ticket_id
     */
    public function __invoke(array $ticketIds): Collection
    {
        if ($ticketIds === []) {
            return collect();
        }

        $tickets = Ticket::query()
            ->whereIn('id', $ticketIds)
            ->get(['id', 'order_id', 'ticket_type_id', 'event_id']);

        $unitPrices = OrderItem::query()
            ->whereIn('order_id', $tickets->pluck('order_id')->unique()->values())
            ->get(['order_id', 'ticket_type_id', 'unit_price_amount', 'currency'])
            ->keyBy(fn (OrderItem $item): string => $item->order_id.'|'.$item->ticket_type_id);

        return $tickets
            ->mapWithKeys(function (Ticket $ticket) use ($unitPrices): array {
                $item = $unitPrices->get($ticket->order_id.'|'.$ticket->ticket_type_id);

                if ($item === null) {
                    return [];
                }

                return [$ticket->id => new TicketSaleFactsData(
                    $ticket->id,
                    $ticket->event_id,
                    $ticket->ticket_type_id,
                    $item->unit_price,
                )];
            });
    }
}
