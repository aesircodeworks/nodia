<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\EventFixture;
use Tests\Isolation\Support\ReportDailySalesFixture;
use Tests\Isolation\Support\TenantFixture;
use Tests\Isolation\Support\TicketTypeFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * report_daily_sales is the standard Rls::applyTenantPolicies posture
 * with no extra policies (stage-11 plan, Data model "report_daily_sales":
 * the RLS policy ships in the same migration, or the isolation suite
 * blocks the merge), mirroring purchase_counters' own shape. Written
 * first per the master plan's TDD sequencing (stage-11 plan, TDD
 * sequencing Slice 1: "Isolation: tenant A cannot read or write tenant
 * B's report_daily_sales rows under the app role"), failing until the
 * report_daily_sales migration and its RLS policy land. Also proves the
 * unique(tenant_id, event_id, ticket_type_id, sales_date) conflict target
 * the future ProjectDailySales upsert relies on (task 5).
 */

/**
 * @return array<string, mixed>
 */
function validDailySalesRow(array $overrides = []): array
{
    return [
        'id' => Str::uuid7()->toString(),
        'tenant_id' => TenantFixture::TENANT_A,
        'event_id' => EventFixture::EVENT_A,
        'ticket_type_id' => TicketTypeFixture::TICKET_TYPE_A,
        'sales_date' => '2026-07-13',
        'tickets_issued_count' => 0,
        'tickets_refunded_count' => 0,
        'gross_amount' => 0,
        'refunded_amount' => 0,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

beforeEach(fn () => ReportDailySalesFixture::seed());

afterEach(fn () => ReportDailySalesFixture::clean());

it('shows a tenant only its own daily sales row', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('report_daily_sales')->pluck('id'),
    );

    expect($ids->all())->toBe([ReportDailySalesFixture::ROW_A]);
});

it('makes a cross-tenant select affect zero rows', function () {
    $row = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('report_daily_sales')->where('id', ReportDailySalesFixture::ROW_B)->first(),
    );

    expect($row)->toBeNull();
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('report_daily_sales')->where('id', ReportDailySalesFixture::ROW_B)->update(['tickets_issued_count' => 99]),
    );

    expect($affected)->toBe(0);

    $count = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('report_daily_sales')->where('id', ReportDailySalesFixture::ROW_B)->value('tickets_issued_count'),
    );

    expect($count)->not->toBe(99);
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('report_daily_sales')->where('id', ReportDailySalesFixture::ROW_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('report_daily_sales')->where('id', ReportDailySalesFixture::ROW_B)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('rejects an insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('report_daily_sales')->insert(validDailySalesRow([
            'tenant_id' => TenantFixture::TENANT_B,
            'event_id' => EventFixture::EVENT_B,
            'ticket_type_id' => TicketTypeFixture::TICKET_TYPE_B,
        ])),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('rejects a second row for the same tenant, event, ticket type, and sales date through the unique constraint', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('report_daily_sales')->insert(validDailySalesRow()),
    ))->toThrow(QueryException::class);
});

it('lets nodia_platform read every tenant\'s daily sales rows without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('report_daily_sales')->pluck('id'));

    expect($ids->all())->toContain(ReportDailySalesFixture::ROW_A, ReportDailySalesFixture::ROW_B);
});

it('rejects a nodia_platform write with no tenant asserted, report_daily_sales has no platform write policy', function () {
    $affected = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('report_daily_sales')->where('id', ReportDailySalesFixture::ROW_A)->update(['tickets_issued_count' => 99]),
    );

    expect($affected)->toBe(0);
});

it('isolates a raw sql query that bypasses all eloquent scoping', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::select('select * from report_daily_sales'),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe(ReportDailySalesFixture::ROW_A);
});
