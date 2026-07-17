<?php

namespace App\Orders\Support;

use App\Orders\Models\Ticket;

/**
 * The rendering seam (stage-08a plan, Slice 10): system-design 15.4
 * allows Gotenberg or dompdf, so GenerateTicketPdf depends on this
 * interface and the concrete choice stays swappable. dompdf is the
 * initial binding: in-process and simpler for CI.
 */
interface TicketPdfRenderer
{
    /**
     * @return string the rendered PDF bytes
     */
    public function render(Ticket $ticket): string;
}
