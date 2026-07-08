<?php

use App\Enums\CheckResult;
use App\Enums\HealthStatus;
use App\Http\Data\HealthChecksData;
use App\Http\Data\HealthReportData;
use Carbon\CarbonImmutable;

test('serializes to the health report contract shape with snake_case keys', function () {
    $report = HealthReportData::make(
        new HealthChecksData(CheckResult::Ok, CheckResult::Failed, CheckResult::Ok),
        CarbonImmutable::parse('2026-07-04 09:00:00', 'America/New_York'),
    );

    expect($report->toArray())->toBe([
        'status' => 'ok',
        'checks' => [
            'database' => 'ok',
            'redis' => 'failed',
            'storage' => 'ok',
        ],
        'checked_at' => '2026-07-04T13:00:00Z',
    ]);
});

test('always reports the ok status regardless of the individual checks', function () {
    $report = HealthReportData::make(
        new HealthChecksData(CheckResult::Ok, CheckResult::Ok, CheckResult::Ok),
        CarbonImmutable::now(),
    );

    expect($report->status)->toBe(HealthStatus::Ok);
});

test('renders checked_at as an ISO 8601 UTC timestamp with a Z suffix', function () {
    $report = HealthReportData::make(
        new HealthChecksData(CheckResult::Ok, CheckResult::Ok, CheckResult::Ok),
        CarbonImmutable::parse('2026-07-04T12:00:00+02:00'),
    );

    expect($report->checkedAt)->toBe('2026-07-04T10:00:00Z');
});

test('exposes the exact contract enum values', function () {
    expect(HealthStatus::Ok->value)->toBe('ok')
        ->and(CheckResult::Ok->value)->toBe('ok')
        ->and(CheckResult::Failed->value)->toBe('failed')
        ->and(HealthStatus::cases())->toHaveCount(1)
        ->and(CheckResult::cases())->toHaveCount(2);
});
