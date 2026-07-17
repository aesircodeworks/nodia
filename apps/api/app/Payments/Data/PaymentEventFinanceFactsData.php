<?php

namespace App\Payments\Data;

use App\Support\Money\Money;

/**
 * The per-payment finance facts App\Payments\Actions
 * \GetPaymentEventFinanceFacts resolves for the Stage 11 finance
 * projector (stage-11 plan, task 8; Data model "report_event_finance"):
 * fee_amount and commission_amount are the row facts persisted at
 * confirmation time (never recomputed from live tenant configuration),
 * and event_id is resolved through the Orders bulk lookup Action, since
 * neither the payment row nor its own outbox payload carries it. Never
 * serialized to the wire, mirroring TicketSaleFactsData's own posture.
 */
final class PaymentEventFinanceFactsData
{
    public function __construct(
        public readonly string $paymentId,
        public readonly string $eventId,
        public readonly Money $feeAmount,
        public readonly Money $commissionAmount,
    ) {}
}
