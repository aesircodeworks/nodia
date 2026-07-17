<?php

use App\Reporting\Jobs\ProjectDailySales;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-11 plan, task 13 Feature test (TDD sequencing Slice 7):
 * `reporting:rebuild {projection} --tenant=` rebuilds only that
 * tenant's rows; the other tenant's rows are left untouched.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    reportingRebuildCleanTenant($this->tenantA);
    reportingRebuildCleanTenant($this->tenantB);
    reportingRebuildCleanGlobal();
});

it('rebuilds only the given tenant, leaving the other tenant row-for-row untouched', function (): void {
    $this->tenantA = reportingRebuildTenant();
    $this->tenantB = reportingRebuildTenant();

    $fxA = reportingRebuildSalesFixture($this->tenantA);
    $fxB = reportingRebuildSalesFixture($this->tenantB);

    reportingRebuildIssueTicket($this->tenantA, $fxA['eventId'], $fxA['ticketTypeId']);
    reportingRebuildIssueTicket($this->tenantB, $fxB['eventId'], $fxB['ticketTypeId']);

    $beforeA = reportingRebuildDailySalesRows($this->tenantA);
    $beforeBRaw = app(TenantTransaction::class)->asTenant(
        $this->tenantB,
        fn () => DB::table('report_daily_sales')->where('tenant_id', $this->tenantB)->orderBy('event_id')->get(),
    );

    $this->travel(config()->integer('outbox.stability_window_seconds') + 1)->seconds();

    Artisan::call('reporting:rebuild', [
        'projection' => ProjectDailySales::NAME,
        '--tenant' => $this->tenantA,
    ]);

    $afterA = reportingRebuildDailySalesRows($this->tenantA);
    $afterBRaw = app(TenantTransaction::class)->asTenant(
        $this->tenantB,
        fn () => DB::table('report_daily_sales')->where('tenant_id', $this->tenantB)->orderBy('event_id')->get(),
    );

    expect($afterA->all())->toEqual($beforeA->all())
        ->and($afterBRaw->toArray())->toEqual($beforeBRaw->toArray());
});
