<?php

namespace App\Reporting\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * sales_date is the UTC calendar day of an outbox event's occurred_at,
 * never the event's local display timezone (stage-11 plan, Risks: "UTC
 * date bucketing"). A pure function so ProjectDailySales's day-bucketing
 * rule is unit-testable at a UTC-midnight boundary without a database.
 */
final class SalesDateBucket
{
    public static function forInstant(CarbonInterface $occurredAt): string
    {
        return CarbonImmutable::instance($occurredAt)->utc()->toDateString();
    }
}
