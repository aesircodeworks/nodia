<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\CustomerFixture;
use Tests\Isolation\Support\DataSubjectRequestFixture;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * data_subject_requests is the standard Rls::applyTenantPolicies posture
 * with no extra policies (stage-12 plan, Data model
 * "data_subject_requests": RLS policy comparing tenant_id to
 * current_setting('app.tenant_id'), in the same migration), the same
 * shape customers already established. Written first per the master
 * plan's TDD sequencing (stage-12 plan, task breakdown item 1: "Isolation
 * for the new table"), failing until the data_subject_requests migration
 * and its RLS policy land.
 */

/**
 * @return array<string, mixed>
 */
function validDataSubjectRequestRow(array $overrides = []): array
{
    return [
        'id' => Str::uuid7()->toString(),
        'tenant_id' => TenantFixture::TENANT_A,
        'customer_id' => CustomerFixture::CUSTOMER_A,
        'type' => 'erasure',
        'status' => 'pending',
        'requested_by_user_id' => DataSubjectRequestFixture::USER_A,
        'completed_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

beforeEach(fn () => DataSubjectRequestFixture::seed());

afterEach(fn () => DataSubjectRequestFixture::clean());

it('shows a tenant only its own data subject request', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('data_subject_requests')->pluck('id'),
    );

    expect($ids->all())->toBe([DataSubjectRequestFixture::REQUEST_A]);
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('data_subject_requests')
            ->where('id', DataSubjectRequestFixture::REQUEST_B)
            ->update(['status' => 'processing']),
    );

    expect($affected)->toBe(0);

    $status = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('data_subject_requests')->where('id', DataSubjectRequestFixture::REQUEST_B)->value('status'),
    );

    expect($status)->toBe('pending');
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('data_subject_requests')->where('id', DataSubjectRequestFixture::REQUEST_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('data_subject_requests')->where('id', DataSubjectRequestFixture::REQUEST_B)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('rejects an insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('data_subject_requests')->insert(validDataSubjectRequestRow(['tenant_id' => TenantFixture::TENANT_B])),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('isolates a raw sql query that bypasses all eloquent scoping', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::select('select * from data_subject_requests'),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe(DataSubjectRequestFixture::REQUEST_A);
});

it('lets nodia_platform read every tenant\'s data subject requests without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('data_subject_requests')->pluck('id'));

    expect($ids->all())->toContain(DataSubjectRequestFixture::REQUEST_A, DataSubjectRequestFixture::REQUEST_B);
});

it('rejects a nodia_platform write with no tenant asserted, data_subject_requests has no platform write policy', function () {
    $affected = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('data_subject_requests')->where('id', DataSubjectRequestFixture::REQUEST_A)->update(['status' => 'processing']),
    );

    expect($affected)->toBe(0);
});

it('rejects an insert violating the open-request-per-customer-and-type partial unique index', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('data_subject_requests')->insert(validDataSubjectRequestRow(['id' => Str::uuid7()->toString()])),
    ))->toThrow(QueryException::class, 'data_subject_requests_open_per_customer_idx');
});
