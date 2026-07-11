<?php

namespace App\Orders\Jobs;

use App\Orders\Models\Ticket;
use App\Orders\Support\TicketPdfRenderer;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OutboxSubscriber;

/**
 * The Orders subscriber for TicketIssued that renders the ticket PDF
 * (stage-08a plan, Slice 10). Idempotent because ticket_pdf is a
 * single-file collection: duplicate generation converges to one
 * attachment rather than accumulating, asserted as exactly one media
 * row. An existing attachment short-circuits so replays do not re-render.
 */
final readonly class GenerateTicketPdf implements OutboxSubscriber
{
    public const string NAME = 'generate_ticket_pdf';

    public function __construct(
        private TicketPdfRenderer $renderer,
    ) {}

    public function handle(OutboxEvent $event): void
    {
        $ticket = Ticket::query()->find($event->aggregate_id);

        if ($ticket === null || $ticket->getMedia('ticket_pdf')->isNotEmpty()) {
            return;
        }

        $ticket->addMediaFromString($this->renderer->render($ticket))
            ->usingFileName('ticket-'.$ticket->id.'.pdf')
            ->withCustomProperties(['mime-type' => 'application/pdf'])
            ->toMediaCollection('ticket_pdf');
    }
}
