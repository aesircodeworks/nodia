<?php

namespace App\Orders\Jobs;

use App\Orders\Models\Order;
use App\Orders\Models\Ticket;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OutboxSubscriber;

/**
 * The Orders subscriber for Identity's CustomerAnonymized (stage-12 plan,
 * Domain events "Consumed"; task breakdown item 4). Overwrites
 * attendee_name with a constant placeholder on every ticket belonging to
 * the anonymized customer's orders, updating Orders' own tickets table
 * only (event-conventions: a consumer never writes to another context's
 * tables; tests/Architecture enforces the boundary). A ticket whose
 * attendee_name is already null is left alone, so a ticket that never
 * carried a name does not start looking like one that did.
 *
 * Idempotent twice over: the Stage 4 outbox_deliveries conditional
 * transition already stops a duplicate delivery from invoking handle()
 * a second time, and even if it did, overwriting the placeholder with
 * itself is a no-op (stage-12 plan, Domain events "Consumed"). A
 * replayed CustomerAnonymized re-scrubs, which is harmless for the same
 * reason.
 */
final readonly class ScrubTicketAttendeeNames implements OutboxSubscriber
{
    public const string NAME = 'scrub_ticket_attendee_names';

    public const string PLACEHOLDER = 'Erased Attendee';

    public function handle(OutboxEvent $event): void
    {
        $customerId = (string) $event->payload['customer_id'];

        Ticket::query()
            ->whereIn('order_id', Order::query()->where('customer_id', $customerId)->select('id'))
            ->whereNotNull('attendee_name')
            ->update(['attendee_name' => self::PLACEHOLDER]);
    }
}
