<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\ExportFixture;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * exports is the standard Rls::applyTenantPolicies posture with no extra
 * policies (stage-11 plan, Data model "exports": the RLS policy ships in
 * the same migration, or the isolation suite blocks the merge), mirroring
 * report_daily_sales' own shape. Written first per the master plan's TDD
 * sequencing (stage-11 plan, TDD sequencing Slice 8: "Isolation: exports
 * cross-tenant denial"), failing until the exports migration and its RLS
 * policy land. Unlike the three projection tables, exports carries no
 * natural business key to make unique, so this file has no
 * unique-constraint case.
 */

/**
 * @return array<string, mixed>
 */
function validExportRow(array $overrides = []): array
{
    return [
        'id' => Str::uuid7()->toString(),
        'tenant_id' => TenantFixture::TENANT_A,
        'type' => 'orders',
        'status' => 'pending',
        'parameters' => json_encode([], JSON_THROW_ON_ERROR),
        'requested_by_user_id' => ExportFixture::USER_A,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

beforeEach(fn () => ExportFixture::seed());

afterEach(fn () => ExportFixture::clean());

it('shows a tenant only its own export row', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('exports')->pluck('id'),
    );

    expect($ids->all())->toBe([ExportFixture::ROW_A]);
});

it('makes a cross-tenant select affect zero rows', function () {
    $row = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('exports')->where('id', ExportFixture::ROW_B)->first(),
    );

    expect($row)->toBeNull();
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('exports')->where('id', ExportFixture::ROW_B)->update(['status' => 'processing']),
    );

    expect($affected)->toBe(0);

    $status = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('exports')->where('id', ExportFixture::ROW_B)->value('status'),
    );

    expect($status)->not->toBe('processing');
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('exports')->where('id', ExportFixture::ROW_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('exports')->where('id', ExportFixture::ROW_B)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('rejects an insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('exports')->insert(validExportRow([
            'tenant_id' => TenantFixture::TENANT_B,
            'requested_by_user_id' => ExportFixture::USER_B,
        ])),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('isolates a raw sql query that bypasses all eloquent scoping', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::select('select * from exports'),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe(ExportFixture::ROW_A);
});
