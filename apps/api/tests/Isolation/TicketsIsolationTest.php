<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\EventFixture;
use Tests\Isolation\Support\OrderFixture;
use Tests\Isolation\Support\OrderItemFixture;
use Tests\Isolation\Support\TenantFixture;
use Tests\Isolation\Support\TicketFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * tickets is the standard Rls::applyTenantPolicies posture with no
 * extra policies (stage-07 plan, Data model "tickets"), mirroring
 * orders. Written first per the master plan's TDD sequencing (stage-07
 * plan, task breakdown item 7).
 */

/**
 * @return array<string, mixed>
 */
function validTicketRow(array $overrides = []): array
{
    return [
        'id' => Str::uuid7()->toString(),
        'tenant_id' => TenantFixture::TENANT_A,
        'order_id' => OrderFixture::ORDER_A,
        'ticket_type_id' => OrderItemFixture::TICKET_TYPE_A,
        'event_id' => EventFixture::EVENT_A,
        'event_seat_id' => null,
        'status' => 'issued',
        'attendee_name' => null,
        'issued_at' => now(),
        'qr_rotation_counter' => 0,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

beforeEach(fn () => TicketFixture::seed());

afterEach(fn () => TicketFixture::clean());

it('shows a tenant only its own tickets', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('tickets')->pluck('id'),
    );

    expect($ids->all())->toBe([TicketFixture::TICKET_A]);
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('tickets')->where('id', TicketFixture::TICKET_B)->update(['status' => 'canceled']),
    );

    expect($affected)->toBe(0);
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('tickets')->where('id', TicketFixture::TICKET_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('tickets')->where('id', TicketFixture::TICKET_B)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('rejects an insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('tickets')->insert(validTicketRow(['tenant_id' => TenantFixture::TENANT_B])),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('lets nodia_platform read every tenant\'s tickets without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('tickets')->pluck('id'));

    expect($ids->all())->toContain(TicketFixture::TICKET_A, TicketFixture::TICKET_B);
});

it('rejects a nodia_platform write with no tenant asserted, tickets has no platform write policy', function () {
    $affected = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('tickets')->where('id', TicketFixture::TICKET_A)->update(['status' => 'canceled']),
    );

    expect($affected)->toBe(0);
});
