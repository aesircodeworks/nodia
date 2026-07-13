<?php

namespace App\Reporting\Jobs;

use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OutboxSubscriber;

/**
 * The Reporting subscriber for Identity's CustomerAnonymized (stage-12
 * plan, Domain events "Consumed"; task breakdown item 5). Stage 11's
 * three read models (report_daily_sales, report_event_finance,
 * report_event_attendance) denormalize event ids, ticket type ids,
 * counts, money amounts, and timestamps only, so there is no PII to
 * scrub today; handle() is intentionally a no-op.
 *
 * The subscriber is still registered so a future read model that
 * denormalizes a customer's name or other PII inherits the scrub
 * obligation visibly: registering it now means the SubscriberRegistry
 * assertion and the outbox_deliveries plumbing already exist, and
 * tests/Feature/Reporting/CustomerAnonymizedConsumerTest.php's own PII
 * column assertion fails loudly the moment such a column is added,
 * pointing straight at this class as the place to implement the scrub
 * (stage-12 plan, Domain events "Consumed": "If Stage 11's read models
 * carry no PII, the subscriber is still registered with a no-op
 * assertion test").
 *
 * Naturally idempotent (a no-op run any number of times has one
 * effect: none), and the Stage 4 outbox_deliveries conditional
 * transition already stops a duplicate delivery from invoking handle()
 * a second time regardless.
 */
final readonly class ScrubReportingPii implements OutboxSubscriber
{
    public const string NAME = 'scrub_reporting_pii';

    public function handle(OutboxEvent $event): void
    {
        // Intentional no-op: see class docblock.
    }
}
