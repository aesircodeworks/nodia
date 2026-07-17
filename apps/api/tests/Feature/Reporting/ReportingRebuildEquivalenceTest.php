<?php

use App\Reporting\Jobs\ProjectDailySales;
use App\Reporting\Jobs\ProjectEventAttendance;
use App\Reporting\Jobs\ProjectEventFinance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-11 plan, task 13 Feature test (TDD sequencing Slice 7, the
 * stage's own rebuild-equivalence mandate; exit criteria 3 and 4): a
 * mixed stream of issues, refunds, payments, completed refunds, and
 * check-ins across two tenants, processed incrementally in shuffled
 * delivery order, frozen past the stability window, then rebuilt for
 * every projection, reproduces the incrementally built state row for
 * row; rebuilding a second time reproduces the same state again.
 */

const REBUILD_PROJECTIONS = [
    ProjectDailySales::NAME,
    ProjectEventFinance::NAME,
    ProjectEventAttendance::NAME,
];

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    $this->travelTo(CarbonImmutable::parse('2026-07-13T13:00:00Z'));

    $this->tenantA = reportingRebuildTenant();
    $this->tenantB = reportingRebuildTenant();
});

afterEach(function (): void {
    reportingRebuildCleanTenant($this->tenantA);
    reportingRebuildCleanTenant($this->tenantB);
    reportingRebuildCleanGlobal();
});

/**
 * @return array{sales: Collection, finance: Collection, attendance: Collection}
 */
function reportingRebuildTenantSnapshot(string $tenantId): array
{
    return [
        'sales' => reportingRebuildDailySalesRows($tenantId),
        'finance' => reportingRebuildEventFinanceRows($tenantId),
        'attendance' => reportingRebuildEventAttendanceRows($tenantId),
    ];
}

function seedReportingRebuildMixedStream(string $tenantId): void
{
    $sales = reportingRebuildSalesFixture($tenantId);
    $first = reportingRebuildIssueTicket($tenantId, $sales['eventId'], $sales['ticketTypeId']);
    reportingRebuildIssueTicket($tenantId, $sales['eventId'], $sales['ticketTypeId']);
    reportingRebuildRefundTicket($tenantId, $first['orderId']);

    $financeEventId = reportingRebuildFinanceEvent($tenantId);
    reportingRebuildConfirmPayment($tenantId, $financeEventId, 10_000, 300);
    reportingRebuildCompleteRefund($tenantId, $financeEventId, 5_000, 150);

    $attendance = reportingRebuildAttendanceFixture($tenantId);
    $ticketOne = reportingRebuildAttendanceTicket($tenantId, $attendance['eventId'], $attendance['ticketTypeId']);
    $ticketTwo = reportingRebuildAttendanceTicket($tenantId, $attendance['eventId'], $attendance['ticketTypeId']);
    reportingRebuildScan($tenantId, $ticketOne, $attendance['eventId'], $attendance['userId'], $attendance['secret'], 'device-1', CarbonImmutable::parse('2026-07-13T12:00:00Z'));
    reportingRebuildScan($tenantId, $ticketTwo, $attendance['eventId'], $attendance['userId'], $attendance['secret'], 'device-2', CarbonImmutable::parse('2026-07-13T12:05:00Z'));
}

it('reproduces the incrementally built state row for row after rebuilding every projection, twice', function (): void {
    Queue::fake();

    seedReportingRebuildMixedStream($this->tenantA);
    seedReportingRebuildMixedStream($this->tenantB);

    $deliveries = collect([
        ...reportingRebuildPendingDeliveries($this->tenantA),
        ...reportingRebuildPendingDeliveries($this->tenantB),
    ])->filter(fn (array $delivery): bool => in_array($delivery['subscriber'], REBUILD_PROJECTIONS, true))
        ->shuffle();

    expect($deliveries)->not->toBeEmpty();

    foreach ($deliveries as $delivery) {
        reportingRebuildProcessDelivery($delivery['eventId'], $delivery['subscriber']);
    }

    $incremental = [
        $this->tenantA => reportingRebuildTenantSnapshot($this->tenantA),
        $this->tenantB => reportingRebuildTenantSnapshot($this->tenantB),
    ];

    expect($incremental[$this->tenantA]['sales'])->not->toBeEmpty()
        ->and($incremental[$this->tenantA]['finance'])->not->toBeEmpty()
        ->and($incremental[$this->tenantA]['attendance'])->not->toBeEmpty();

    $this->travel(config()->integer('outbox.stability_window_seconds') + 1)->seconds();

    foreach (REBUILD_PROJECTIONS as $projection) {
        Artisan::call('reporting:rebuild', ['projection' => $projection]);
    }

    $rebuilt = [
        $this->tenantA => reportingRebuildTenantSnapshot($this->tenantA),
        $this->tenantB => reportingRebuildTenantSnapshot($this->tenantB),
    ];

    expect($rebuilt[$this->tenantA]['sales']->all())->toEqual($incremental[$this->tenantA]['sales']->all())
        ->and($rebuilt[$this->tenantA]['finance']->all())->toEqual($incremental[$this->tenantA]['finance']->all())
        ->and($rebuilt[$this->tenantA]['attendance']->all())->toEqual($incremental[$this->tenantA]['attendance']->all())
        ->and($rebuilt[$this->tenantB]['sales']->all())->toEqual($incremental[$this->tenantB]['sales']->all())
        ->and($rebuilt[$this->tenantB]['finance']->all())->toEqual($incremental[$this->tenantB]['finance']->all())
        ->and($rebuilt[$this->tenantB]['attendance']->all())->toEqual($incremental[$this->tenantB]['attendance']->all());

    foreach (REBUILD_PROJECTIONS as $projection) {
        Artisan::call('reporting:rebuild', ['projection' => $projection]);
    }

    $rebuiltAgain = [
        $this->tenantA => reportingRebuildTenantSnapshot($this->tenantA),
        $this->tenantB => reportingRebuildTenantSnapshot($this->tenantB),
    ];

    expect($rebuiltAgain[$this->tenantA]['sales']->all())->toEqual($incremental[$this->tenantA]['sales']->all())
        ->and($rebuiltAgain[$this->tenantA]['finance']->all())->toEqual($incremental[$this->tenantA]['finance']->all())
        ->and($rebuiltAgain[$this->tenantA]['attendance']->all())->toEqual($incremental[$this->tenantA]['attendance']->all())
        ->and($rebuiltAgain[$this->tenantB]['sales']->all())->toEqual($incremental[$this->tenantB]['sales']->all())
        ->and($rebuiltAgain[$this->tenantB]['finance']->all())->toEqual($incremental[$this->tenantB]['finance']->all())
        ->and($rebuiltAgain[$this->tenantB]['attendance']->all())->toEqual($incremental[$this->tenantB]['attendance']->all());
});
