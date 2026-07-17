<?php

use App\Reporting\Support\SalesDateBucket;
use Carbon\CarbonImmutable;

/*
 * Stage-11 plan, Slice 1 Unit tests: sales_date is the UTC calendar day
 * of occurred_at (Data model "report_daily_sales"), never the wall-clock
 * "now", so the fake clock only proves the pure function reads the
 * instant it is given, not ambient time.
 */

it('buckets an instant already at UTC midnight as that same day', function (): void {
    expect(SalesDateBucket::forInstant(CarbonImmutable::parse('2026-07-13T00:00:00Z')))->toBe('2026-07-13');
});

it('buckets an instant just before UTC midnight as the earlier day', function (): void {
    expect(SalesDateBucket::forInstant(CarbonImmutable::parse('2026-07-13T23:59:59Z')))->toBe('2026-07-13');
});

it('buckets an instant one second later, past the UTC midnight boundary, as the next day', function (): void {
    expect(SalesDateBucket::forInstant(CarbonImmutable::parse('2026-07-14T00:00:00Z')))->toBe('2026-07-14');
});

it('converts a non-UTC offset instant to its UTC calendar day before bucketing', function (): void {
    // 2026-07-13T21:30:00-05:00 is 2026-07-14T02:30:00Z: the local day and
    // the UTC day genuinely differ, proving the function normalizes to UTC
    // rather than trusting the instant's own offset's calendar day.
    expect(SalesDateBucket::forInstant(CarbonImmutable::parse('2026-07-13T21:30:00-05:00')))->toBe('2026-07-14');
});

it('reads the instant it is given rather than the ambient fake-clock time', function (): void {
    test()->travelTo(CarbonImmutable::parse('2026-01-01T00:00:00Z'));

    expect(SalesDateBucket::forInstant(CarbonImmutable::parse('2026-07-13T12:00:00Z')))->toBe('2026-07-13');
});
