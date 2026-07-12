<?php

namespace App\Payments\Enums;

/**
 * Gateway payout mirror lifecycle (stage-08c plan, Data model "payouts";
 * system-design 8.3). paid, failed, and canceled are terminal. Every
 * transition is a conditional UPDATE checked by affected-row count, never
 * read-then-write.
 */
enum PayoutStatus: string
{
    case Pending = 'pending';
    case InTransit = 'in_transit';
    case Paid = 'paid';
    case Failed = 'failed';
    case Canceled = 'canceled';
}
