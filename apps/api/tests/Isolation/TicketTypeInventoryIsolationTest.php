<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\TenantFixture;
use Tests\Isolation\Support\TicketTypeFixture;
use Tests\Isolation\Support\TicketTypeInventoryFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * ticket_type_inventory is the standard Rls::applyTenantPolicies posture
 * with no extra policies (stage-06 plan, Data model
 * "ticket_type_inventory": tenant-scoped, mirroring ticket_types), the
 * same shape ticket_types already established. Written first per the
 * master plan's TDD sequencing (stage-06 plan, task breakdown item 1's
 * slice 0 probe, merged with item 2), failing until the
 * ticket_type_inventory migration and its RLS policy land. Also proves
 * the four CHECK constraints the creating migration ships
 * (ticket_type_inventory_quantity_non_negative,
 * ticket_type_inventory_sold_non_negative,
 * ticket_type_inventory_held_non_negative,
 * ticket_type_inventory_no_oversell) regardless of tenant context,
 * mirroring ticket_types_price_amount_non_negative's own isolation
 * coverage.
 */

/**
 * @return array<string, mixed>
 */
function validTicketTypeInventoryRow(array $overrides = []): array
{
    return [
        'id' => Str::uuid7()->toString(),
        'tenant_id' => TenantFixture::TENANT_A,
        'ticket_type_id' => TicketTypeFixture::TICKET_TYPE_A,
        'quantity' => 100,
        'sold' => 0,
        'held' => 0,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

beforeEach(fn () => TicketTypeInventoryFixture::seed());

afterEach(fn () => TicketTypeInventoryFixture::clean());

it('shows a tenant only its own ticket type inventory row', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('ticket_type_inventory')->pluck('id'),
    );

    expect($ids->all())->toBe([TicketTypeInventoryFixture::INVENTORY_A]);
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('ticket_type_inventory')
            ->where('id', TicketTypeInventoryFixture::INVENTORY_B)
            ->update(['quantity' => 9999]),
    );

    expect($affected)->toBe(0);

    $quantity = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('ticket_type_inventory')->where('id', TicketTypeInventoryFixture::INVENTORY_B)->value('quantity'),
    );

    expect($quantity)->not->toBe(9999);
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('ticket_type_inventory')->where('id', TicketTypeInventoryFixture::INVENTORY_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('ticket_type_inventory')->where('id', TicketTypeInventoryFixture::INVENTORY_B)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('rejects an insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('ticket_type_inventory')->insert(validTicketTypeInventoryRow(['tenant_id' => TenantFixture::TENANT_B])),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('ships the quantity non-negative CHECK constraint (unreachable independently of no-oversell: a negative quantity always also requires a negative sold or held)', function () {
    $constraint = DB::selectOne(
        "select conname from pg_constraint where conname = 'ticket_type_inventory_quantity_non_negative'",
    );

    expect($constraint)->not->toBeNull();
});

it('rejects an insert violating the sold non-negative CHECK regardless of tenant context', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('ticket_type_inventory')->insert(validTicketTypeInventoryRow(['id' => Str::uuid7()->toString(), 'ticket_type_id' => TicketTypeFixture::TICKET_TYPE_A, 'sold' => -1])),
    ))->toThrow(QueryException::class, 'ticket_type_inventory_sold_non_negative');
});

it('rejects an insert violating the held non-negative CHECK regardless of tenant context', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('ticket_type_inventory')->insert(validTicketTypeInventoryRow(['id' => Str::uuid7()->toString(), 'ticket_type_id' => TicketTypeFixture::TICKET_TYPE_A, 'held' => -1])),
    ))->toThrow(QueryException::class, 'ticket_type_inventory_held_non_negative');
});

it('rejects an insert violating the no-oversell CHECK regardless of tenant context', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('ticket_type_inventory')->insert(validTicketTypeInventoryRow([
            'id' => Str::uuid7()->toString(),
            'ticket_type_id' => TicketTypeFixture::TICKET_TYPE_A,
            'quantity' => 10,
            'sold' => 6,
            'held' => 5,
        ])),
    ))->toThrow(QueryException::class, 'ticket_type_inventory_no_oversell');
});

it('isolates a raw sql query that bypasses all eloquent scoping', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::select('select * from ticket_type_inventory'),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe(TicketTypeInventoryFixture::INVENTORY_A);
});

it('lets nodia_platform read every tenant\'s ticket type inventory without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('ticket_type_inventory')->pluck('id'));

    expect($ids->all())->toContain(TicketTypeInventoryFixture::INVENTORY_A, TicketTypeInventoryFixture::INVENTORY_B);
});

it('rejects a nodia_platform write with no tenant asserted, ticket_type_inventory has no platform write policy', function () {
    $affected = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('ticket_type_inventory')->where('id', TicketTypeInventoryFixture::INVENTORY_A)->update(['quantity' => 9999]),
    );

    expect($affected)->toBe(0);
});

it('proves the held-increment conditional UPDATE affects zero rows when it would break the no-oversell invariant', function () {
    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
        DB::table('ticket_type_inventory')
            ->where('id', TicketTypeInventoryFixture::INVENTORY_A)
            ->update(['quantity' => 10, 'sold' => 0, 'held' => 10]);
    });

    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::update(
            'update ticket_type_inventory set held = held + ? where ticket_type_id = ? and sold + held + ? <= quantity',
            [1, TicketTypeFixture::TICKET_TYPE_A, 1],
        ),
    );

    expect($affected)->toBe(0);
});

it('proves the held-increment conditional UPDATE affects exactly one row when capacity is available', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::update(
            'update ticket_type_inventory set held = held + ? where ticket_type_id = ? and sold + held + ? <= quantity',
            [1, TicketTypeFixture::TICKET_TYPE_A, 1],
        ),
    );

    expect($affected)->toBe(1);

    $held = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('ticket_type_inventory')->where('id', TicketTypeInventoryFixture::INVENTORY_A)->value('held'),
    );

    expect($held)->toBe(1);
});
