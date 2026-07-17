<?php

namespace App\Support\Outbox;

/**
 * Ordered consumer whose ordering key is derived from the event payload
 * rather than the envelope aggregate (stage-08b plan, task 5): the
 * ledger projection consumes PaymentConfirmed (aggregate payment) and
 * RefundCompleted (aggregate refund), which must order per payment, a
 * key both payloads carry. Events whose payload lacks the field fall
 * back to the envelope-aggregate ordering of OrderedConsumption.
 */
interface KeyedOrderedOutboxSubscriber extends OrderedOutboxSubscriber
{
    /**
     * Top-level snake_case payload field whose value groups events into
     * one ordered sequence across aggregates.
     */
    public function orderingKeyPayloadPath(): string;
}
