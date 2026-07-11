<?php

namespace App\Orders\Support;

use App\Orders\Models\Ticket;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * The in-process renderer (system-design 15.4; the dompdf-or-Gotenberg
 * choice is recorded in the task PR per the stage-08a plan's Risks).
 * The document carries the ticket's human-facing facts only: no QR
 * payload is ever embedded in a durable artifact, since payloads are
 * generated on render and rotation must invalidate anything previously
 * issued (system-design 8.3, 14.4).
 */
final class DompdfTicketPdfRenderer implements TicketPdfRenderer
{
    public function render(Ticket $ticket): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($ticket));
        $dompdf->setPaper('a4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    private function html(Ticket $ticket): string
    {
        return sprintf(
            '<html><body><h1>Your ticket</h1><p>Attendee: %s</p><p>Issued at: %s</p><p>Present the code from your account at the entrance.</p></body></html>',
            e($ticket->attendee_name ?? 'Guest'),
            e($ticket->issued_at->toDateTimeString()),
        );
    }
}
