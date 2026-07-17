<?php

namespace App\Orders\Enums;

/**
 * The order state machine states from system-design 7.1. The refund
 * states exist from day one so Stage 8b's refund arcs extend the
 * transition table additively, but no transition Action targets them
 * in Stage 7.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case AwaitingPayment = 'awaiting_payment';
    case Paid = 'paid';
    case Expired = 'expired';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
}
