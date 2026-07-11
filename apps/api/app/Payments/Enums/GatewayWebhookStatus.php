<?php

namespace App\Payments\Enums;

/**
 * received rows await ProcessGatewayWebhook; processed rows applied a
 * payment transition; ignored covers duplicates of already-terminal
 * payments, unmatched references, and event types with no payment
 * consequence (stage-08a plan, Data model "gateway_webhook_events").
 */
enum GatewayWebhookStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Ignored = 'ignored';
}
