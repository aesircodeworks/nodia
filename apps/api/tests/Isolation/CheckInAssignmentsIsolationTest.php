<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\CheckInAssignmentFixture;
use Tests\Isolation\Support\EventFixture;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * check_in_assignments is the standard Rls::applyTenantPolicies posture
 * with no extra policies (stage-09 plan, Data model
 * "check_in_assignments"), mirroring check_ins. Written first per the
 * master plan's TDD sequencing (stage-09 plan, Slice 1).
 */

/**
 * @return array<string, mixed>
 */
function validCheckInAssignmentRow(array $overrides = []): array
{
    return [
        'id' => Str::uuid7()->toString(),
        'tenant_id' => TenantFixture::TENANT_A,
        'event_id' => EventFixture::EVENT_A,
        'user_id' => CheckInAssignmentFixture::USER_A,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

beforeEach(fn () => CheckInAssignmentFixture::seed());

afterEach(fn () => CheckInAssignmentFixture::clean());

it('shows a tenant only its own check-in assignments', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('check_in_assignments')->pluck('id'),
    );

    expect($ids->all())->toBe([CheckInAssignmentFixture::ASSIGNMENT_A]);
});

it('finds nothing across tenants with a cross-tenant select', function () {
    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('check_in_assignments')->where('id', CheckInAssignmentFixture::ASSIGNMENT_B)->exists(),
    );

    expect($exists)->toBeFalse();
});

it('rejects a cross-tenant insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('check_in_assignments')->insert(validCheckInAssignmentRow([
            'tenant_id' => TenantFixture::TENANT_B,
            'event_id' => EventFixture::EVENT_B,
            'user_id' => CheckInAssignmentFixture::USER_B,
        ])),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('check_in_assignments')->where('id', CheckInAssignmentFixture::ASSIGNMENT_B)->update(['user_id' => CheckInAssignmentFixture::USER_A]),
    );

    expect($affected)->toBe(0);

    $userId = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('check_in_assignments')->where('id', CheckInAssignmentFixture::ASSIGNMENT_B)->value('user_id'),
    );

    expect($userId)->toBe(CheckInAssignmentFixture::USER_B);
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('check_in_assignments')->where('id', CheckInAssignmentFixture::ASSIGNMENT_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('check_in_assignments')->where('id', CheckInAssignmentFixture::ASSIGNMENT_B)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('isolates a raw sql query that bypasses all eloquent scoping', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::select('select * from check_in_assignments'),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe(CheckInAssignmentFixture::ASSIGNMENT_A);
});

it('lets nodia_platform read every tenant\'s check-in assignments without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('check_in_assignments')->pluck('id'));

    expect($ids->all())->toContain(CheckInAssignmentFixture::ASSIGNMENT_A, CheckInAssignmentFixture::ASSIGNMENT_B);
});

it('rejects a nodia_platform write with no tenant asserted, check_in_assignments has no platform write policy', function () {
    $affected = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('check_in_assignments')->where('id', CheckInAssignmentFixture::ASSIGNMENT_A)->update(['user_id' => CheckInAssignmentFixture::USER_B]),
    );

    expect($affected)->toBe(0);
});
