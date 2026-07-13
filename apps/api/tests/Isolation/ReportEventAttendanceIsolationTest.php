<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\EventFixture;
use Tests\Isolation\Support\ReportEventAttendanceFixture;
use Tests\Isolation\Support\TenantFixture;
use Tests\Isolation\Support\TicketTypeFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * report_event_attendance is the standard Rls::applyTenantPolicies
 * posture with no extra policies (stage-11 plan, Data model
 * "report_event_attendance": the RLS policy ships in the same
 * migration, or the isolation suite blocks the merge), mirroring
 * report_daily_sales' and report_event_finance's own shape. Written
 * first per the master plan's TDD sequencing (stage-11 plan, TDD
 * sequencing Slice 5: "Isolation: report_event_attendance cross-tenant
 * denial"), failing until the report_event_attendance migration and its
 * RLS policy land. Also proves the unique(tenant_id, event_id,
 * ticket_type_id) conflict target the future ProjectEventAttendance
 * upsert relies on (task 11).
 */

/**
 * @return array<string, mixed>
 */
function validEventAttendanceRow(array $overrides = []): array
{
    return [
        'id' => Str::uuid7()->toString(),
        'tenant_id' => TenantFixture::TENANT_A,
        'event_id' => EventFixture::EVENT_A,
        'ticket_type_id' => TicketTypeFixture::TICKET_TYPE_A,
        'checked_in_count' => 0,
        'duplicate_scan_count' => 0,
        'first_scan_at' => null,
        'last_scan_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

beforeEach(fn () => ReportEventAttendanceFixture::seed());

afterEach(fn () => ReportEventAttendanceFixture::clean());

it('shows a tenant only its own event attendance row', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('report_event_attendance')->pluck('id'),
    );

    expect($ids->all())->toBe([ReportEventAttendanceFixture::ROW_A]);
});

it('makes a cross-tenant select affect zero rows', function () {
    $row = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('report_event_attendance')->where('id', ReportEventAttendanceFixture::ROW_B)->first(),
    );

    expect($row)->toBeNull();
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('report_event_attendance')->where('id', ReportEventAttendanceFixture::ROW_B)->update(['checked_in_count' => 99]),
    );

    expect($affected)->toBe(0);

    $count = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('report_event_attendance')->where('id', ReportEventAttendanceFixture::ROW_B)->value('checked_in_count'),
    );

    expect($count)->not->toBe(99);
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('report_event_attendance')->where('id', ReportEventAttendanceFixture::ROW_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('report_event_attendance')->where('id', ReportEventAttendanceFixture::ROW_B)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('rejects an insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('report_event_attendance')->insert(validEventAttendanceRow([
            'tenant_id' => TenantFixture::TENANT_B,
            'event_id' => EventFixture::EVENT_B,
            'ticket_type_id' => TicketTypeFixture::TICKET_TYPE_B,
        ])),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('rejects a second row for the same tenant, event, and ticket type through the unique constraint', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('report_event_attendance')->insert(validEventAttendanceRow()),
    ))->toThrow(QueryException::class);
});

it('lets nodia_platform read every tenant\'s event attendance rows without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('report_event_attendance')->pluck('id'));

    expect($ids->all())->toContain(ReportEventAttendanceFixture::ROW_A, ReportEventAttendanceFixture::ROW_B);
});

it('rejects a nodia_platform write with no tenant asserted, report_event_attendance has no platform write policy', function () {
    $affected = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('report_event_attendance')->where('id', ReportEventAttendanceFixture::ROW_A)->update(['checked_in_count' => 99]),
    );

    expect($affected)->toBe(0);
});

it('isolates a raw sql query that bypasses all eloquent scoping', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::select('select * from report_event_attendance'),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe(ReportEventAttendanceFixture::ROW_A);
});
