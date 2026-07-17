<?php

namespace App\Payments\Gateways;

enum WebhookKind: string
{
    case Confirmed = 'confirmed';
    case Failed = 'failed';
    case RefundCompleted = 'refund_completed';
    case RefundFailed = 'refund_failed';
}
