<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\CheckInFixture;
use Tests\Isolation\Support\EventFixture;
use Tests\Isolation\Support\TenantFixture;
use Tests\Isolation\Support\TicketFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * check_ins is the standard Rls::applyTenantPolicies posture with no
 * extra policies (stage-09 plan, Data model "check_ins"), mirroring
 * tickets. Written first per the master plan's TDD sequencing (stage-09
 * plan, Slice 1).
 */

/**
 * @return array<string, mixed>
 */
function validCheckInRow(array $overrides = []): array
{
    return [
        'id' => Str::uuid7()->toString(),
        'tenant_id' => TenantFixture::TENANT_A,
        'ticket_id' => TicketFixture::TICKET_A,
        'event_id' => EventFixture::EVENT_A,
        'user_id' => CheckInFixture::USER_A,
        'device_id' => 'device-1',
        'client_scan_id' => Str::uuid7()->toString(),
        'result' => 'accepted',
        'scanned_at' => now(),
        'synced_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

beforeEach(fn () => CheckInFixture::seed());

afterEach(fn () => CheckInFixture::clean());

it('shows a tenant only its own check-ins', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('check_ins')->pluck('id'),
    );

    expect($ids->all())->toBe([CheckInFixture::CHECK_IN_A]);
});

it('finds nothing across tenants with a cross-tenant select', function () {
    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('check_ins')->where('id', CheckInFixture::CHECK_IN_B)->exists(),
    );

    expect($exists)->toBeFalse();
});

it('rejects a cross-tenant insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('check_ins')->insert(validCheckInRow([
            'tenant_id' => TenantFixture::TENANT_B,
            'ticket_id' => TicketFixture::TICKET_B,
            'event_id' => EventFixture::EVENT_B,
            'user_id' => CheckInFixture::USER_B,
        ])),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('check_ins')->where('id', CheckInFixture::CHECK_IN_B)->update(['result' => 'duplicate']),
    );

    expect($affected)->toBe(0);

    $result = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('check_ins')->where('id', CheckInFixture::CHECK_IN_B)->value('result'),
    );

    expect($result)->toBe('accepted');
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('check_ins')->where('id', CheckInFixture::CHECK_IN_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('check_ins')->where('id', CheckInFixture::CHECK_IN_B)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('isolates a raw sql query that bypasses all eloquent scoping', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::select('select * from check_ins'),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe(CheckInFixture::CHECK_IN_A);
});

it('lets nodia_platform read every tenant\'s check-ins without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('check_ins')->pluck('id'));

    expect($ids->all())->toContain(CheckInFixture::CHECK_IN_A, CheckInFixture::CHECK_IN_B);
});

it('rejects a nodia_platform write with no tenant asserted, check_ins has no platform write policy', function () {
    $affected = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('check_ins')->where('id', CheckInFixture::CHECK_IN_A)->update(['result' => 'duplicate']),
    );

    expect($affected)->toBe(0);
});
