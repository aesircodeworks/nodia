<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\EventFixture;
use Tests\Isolation\Support\ReportEventFinanceFixture;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * report_event_finance is the standard Rls::applyTenantPolicies posture
 * with no extra policies (stage-11 plan, Data model "report_event_finance":
 * the RLS policy ships in the same migration, or the isolation suite
 * blocks the merge), mirroring report_daily_sales' own shape. Written
 * first per the master plan's TDD sequencing (stage-11 plan, TDD
 * sequencing Slice 3: "Isolation: report_event_finance cross-tenant
 * denial"), failing until the report_event_finance migration and its RLS
 * policy land. Also proves the unique(tenant_id, event_id) conflict
 * target the future ProjectEventFinance upsert relies on (task 8).
 */

/**
 * @return array<string, mixed>
 */
function validEventFinanceRow(array $overrides = []): array
{
    return [
        'id' => Str::uuid7()->toString(),
        'tenant_id' => TenantFixture::TENANT_A,
        'event_id' => EventFixture::EVENT_A,
        'orders_paid_count' => 0,
        'refunds_count' => 0,
        'gross_amount' => 0,
        'gateway_fee_amount' => 0,
        'platform_commission_amount' => 0,
        'tenant_net_amount' => 0,
        'refunded_amount' => 0,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

beforeEach(fn () => ReportEventFinanceFixture::seed());

afterEach(fn () => ReportEventFinanceFixture::clean());

it('shows a tenant only its own event finance row', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('report_event_finance')->pluck('id'),
    );

    expect($ids->all())->toBe([ReportEventFinanceFixture::ROW_A]);
});

it('makes a cross-tenant select affect zero rows', function () {
    $row = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('report_event_finance')->where('id', ReportEventFinanceFixture::ROW_B)->first(),
    );

    expect($row)->toBeNull();
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('report_event_finance')->where('id', ReportEventFinanceFixture::ROW_B)->update(['orders_paid_count' => 99]),
    );

    expect($affected)->toBe(0);

    $count = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('report_event_finance')->where('id', ReportEventFinanceFixture::ROW_B)->value('orders_paid_count'),
    );

    expect($count)->not->toBe(99);
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('report_event_finance')->where('id', ReportEventFinanceFixture::ROW_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('report_event_finance')->where('id', ReportEventFinanceFixture::ROW_B)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('rejects an insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('report_event_finance')->insert(validEventFinanceRow([
            'tenant_id' => TenantFixture::TENANT_B,
            'event_id' => EventFixture::EVENT_B,
        ])),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('rejects a second row for the same tenant and event through the unique constraint', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('report_event_finance')->insert(validEventFinanceRow()),
    ))->toThrow(QueryException::class);
});

it('lets nodia_platform read every tenant\'s event finance rows without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('report_event_finance')->pluck('id'));

    expect($ids->all())->toContain(ReportEventFinanceFixture::ROW_A, ReportEventFinanceFixture::ROW_B);
});

it('rejects a nodia_platform write with no tenant asserted, report_event_finance has no platform write policy', function () {
    $affected = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('report_event_finance')->where('id', ReportEventFinanceFixture::ROW_A)->update(['orders_paid_count' => 99]),
    );

    expect($affected)->toBe(0);
});

it('isolates a raw sql query that bypasses all eloquent scoping', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::select('select * from report_event_finance'),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe(ReportEventFinanceFixture::ROW_A);
});
