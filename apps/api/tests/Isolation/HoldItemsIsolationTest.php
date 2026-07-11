<?php

use App\EventCatalog\Models\TicketType;
use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\EventFixture;
use Tests\Isolation\Support\HoldFixture;
use Tests\Isolation\Support\HoldItemFixture;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * hold_items is the standard Rls::applyTenantPolicies posture with no
 * extra policies (stage-06 plan, Data model "hold_items"), tenant_id
 * denormalized even though derivable through hold_id. Also proves the
 * hold_items_quantity_positive CHECK regardless of tenant context,
 * mirroring ticket_type_inventory's own CHECK isolation coverage.
 */

/**
 * @return array<string, mixed>
 */
function validHoldItemRow(array $overrides = []): array
{
    return [
        'id' => Str::uuid7()->toString(),
        'tenant_id' => TenantFixture::TENANT_A,
        'hold_id' => HoldFixture::HOLD_A,
        'ticket_type_id' => HoldItemFixture::TICKET_TYPE_A,
        'quantity' => 1,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

beforeEach(fn () => HoldItemFixture::seed());

afterEach(fn () => HoldItemFixture::clean());

it('shows a tenant only its own hold item', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('hold_items')->pluck('id'),
    );

    expect($ids->all())->toBe([HoldItemFixture::ITEM_A]);
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('hold_items')->where('id', HoldItemFixture::ITEM_B)->update(['quantity' => 99]),
    );

    expect($affected)->toBe(0);
});

it('rejects an insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('hold_items')->insert(validHoldItemRow(['tenant_id' => TenantFixture::TENANT_B])),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('rejects an insert violating the quantity positive CHECK regardless of tenant context', function () {
    $otherTicketTypeId = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => TicketType::factory()->create([
            'tenant_id' => TenantFixture::TENANT_A,
            'event_id' => EventFixture::EVENT_A,
        ])->id,
    );

    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('hold_items')->insert(validHoldItemRow([
            'ticket_type_id' => $otherTicketTypeId,
            'quantity' => 0,
        ])),
    ))->toThrow(QueryException::class, 'hold_items_quantity_positive');

    actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('ticket_types')->where('id', $otherTicketTypeId)->delete(),
    );
});

it('lets nodia_platform read every tenant\'s hold items without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('hold_items')->pluck('id'));

    expect($ids->all())->toContain(HoldItemFixture::ITEM_A, HoldItemFixture::ITEM_B);
});
