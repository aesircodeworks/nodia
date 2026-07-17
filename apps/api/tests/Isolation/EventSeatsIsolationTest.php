<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\EventSeatFixture;
use Tests\Isolation\Support\SeatFixture;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * event_seats is the standard Rls::applyTenantPolicies posture with no
 * extra policies (stage-06 plan, Data model "event_seats"), mirroring
 * holds. Written first per the master plan's TDD sequencing (stage-06
 * plan, Slice 5: "Isolation (first): event_seats probe green").
 */

/**
 * @return array<string, mixed>
 */
function validEventSeatRow(array $overrides = []): array
{
    return [
        'id' => Str::uuid7()->toString(),
        'tenant_id' => TenantFixture::TENANT_A,
        'event_id' => EventSeatFixture::EVENT_A,
        'seat_id' => SeatFixture::SEAT_A,
        'ticket_type_id' => null,
        'status' => 'available',
        'hold_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

beforeEach(fn () => EventSeatFixture::seed());

afterEach(fn () => EventSeatFixture::clean());

it('shows a tenant only its own event seat', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('event_seats')->pluck('id'),
    );

    expect($ids->all())->toBe([EventSeatFixture::EVENT_SEAT_A]);
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('event_seats')->where('id', EventSeatFixture::EVENT_SEAT_B)->update(['status' => 'blocked']),
    );

    expect($affected)->toBe(0);

    $status = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('event_seats')->where('id', EventSeatFixture::EVENT_SEAT_B)->value('status'),
    );

    expect($status)->toBe('available');
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('event_seats')->where('id', EventSeatFixture::EVENT_SEAT_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('event_seats')->where('id', EventSeatFixture::EVENT_SEAT_B)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('rejects an insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('event_seats')->insert(validEventSeatRow(['tenant_id' => TenantFixture::TENANT_B])),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('isolates a raw sql query that bypasses all eloquent scoping', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::select('select * from event_seats'),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe(EventSeatFixture::EVENT_SEAT_A);
});

it('lets nodia_platform read every tenant\'s event seats without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('event_seats')->pluck('id'));

    expect($ids->all())->toContain(EventSeatFixture::EVENT_SEAT_A, EventSeatFixture::EVENT_SEAT_B);
});

it('rejects a nodia_platform write with no tenant asserted, event_seats has no platform write policy', function () {
    $affected = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('event_seats')->where('id', EventSeatFixture::EVENT_SEAT_A)->update(['status' => 'blocked']),
    );

    expect($affected)->toBe(0);
});
