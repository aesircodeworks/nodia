<?php

use App\Reporting\Jobs\ProjectDailySales;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-11 plan, task 13 Feature test (TDD sequencing Slice 7):
 * `reporting:rebuild {projection} --verify` reports zero drift on a
 * healthy projection and nonzero drift after a row is deliberately
 * corrupted, and never writes to the projection table either way.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = reportingRebuildTenant();
});

afterEach(function (): void {
    reportingRebuildCleanTenant($this->tenantId);
    reportingRebuildCleanGlobal();
});

it('reports zero drift on a healthy projection and writes nothing', function (): void {
    $fx = reportingRebuildSalesFixture($this->tenantId);
    reportingRebuildIssueTicket($this->tenantId, $fx['eventId'], $fx['ticketTypeId']);

    $before = reportingRebuildDailySalesRows($this->tenantId);

    $this->travel(config()->integer('outbox.stability_window_seconds') + 1)->seconds();

    Artisan::call('reporting:rebuild', [
        'projection' => ProjectDailySales::NAME,
        '--verify' => true,
    ]);

    $output = Artisan::output();

    $after = reportingRebuildDailySalesRows($this->tenantId);

    expect($output)->toContain('0 missing, 0 extra, 0 mismatched')
        ->and(Artisan::call('reporting:rebuild', ['projection' => ProjectDailySales::NAME, '--verify' => true]))->toBe(0)
        ->and($after->all())->toEqual($before->all());
});

it('reports nonzero drift after a row is corrupted, and still writes nothing', function (): void {
    $fx = reportingRebuildSalesFixture($this->tenantId);
    reportingRebuildIssueTicket($this->tenantId, $fx['eventId'], $fx['ticketTypeId']);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($fx): void {
        DB::table('report_daily_sales')
            ->where('event_id', $fx['eventId'])
            ->where('ticket_type_id', $fx['ticketTypeId'])
            ->update(['gross_amount' => 999_999]);
    });

    $corrupted = reportingRebuildDailySalesRows($this->tenantId);

    $this->travel(config()->integer('outbox.stability_window_seconds') + 1)->seconds();

    $exitCode = Artisan::call('reporting:rebuild', [
        'projection' => ProjectDailySales::NAME,
        '--verify' => true,
    ]);

    $output = Artisan::output();

    $after = reportingRebuildDailySalesRows($this->tenantId);

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('1 mismatched')
        ->and($after->all())->toEqual($corrupted->all());
});
