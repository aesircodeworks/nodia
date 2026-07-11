<?php

namespace App\Orders\Enums;

/**
 * Only Issued is reachable in Stage 7; Canceled and Refunded arrive
 * with Stage 8b's refund execution.
 */
enum TicketStatus: string
{
    case Issued = 'issued';
    case Canceled = 'canceled';
    case Refunded = 'refunded';
}
