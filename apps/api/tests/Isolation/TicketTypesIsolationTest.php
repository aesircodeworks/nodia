<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\EventFixture;
use Tests\Isolation\Support\TenantFixture;
use Tests\Isolation\Support\TicketTypeFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * ticket_types is the standard Rls::applyTenantPolicies posture with no
 * extra policies (stage-05a plan Data model: ticket type mutation is
 * tenant admin surface, mirroring venues and events), the same shape those
 * two tables already established. Written first per the master plan's TDD
 * sequencing (stage-05a plan, TDD sequencing, Slice 3: "Isolation (first):
 * probes against ticket_types"), failing until the ticket_types migration
 * and its RLS policy land. Also proves the two CHECK constraints the
 * creating migration ships (ticket_types_price_amount_non_negative,
 * ticket_types_sales_window) regardless of tenant context, mirroring
 * venues_capacity_positive/events_end_after_start's own isolation coverage.
 */

/**
 * @return array<string, mixed>
 */
function validTicketTypeRow(array $overrides = []): array
{
    return [
        'id' => Str::uuid7()->toString(),
        'tenant_id' => TenantFixture::TENANT_A,
        'event_id' => EventFixture::EVENT_A,
        'name' => 'Forged Ticket Type',
        'price_amount' => 5000,
        'currency' => 'USD',
        'sales_start' => null,
        'sales_end' => null,
        'requires_seat' => false,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

beforeEach(fn () => TicketTypeFixture::seed());

afterEach(fn () => TicketTypeFixture::clean());

it('shows a tenant only its own ticket type row', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('ticket_types')->pluck('id'),
    );

    expect($ids->all())->toBe([TicketTypeFixture::TICKET_TYPE_A]);
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('ticket_types')
            ->where('id', TicketTypeFixture::TICKET_TYPE_B)
            ->update(['name' => 'Hijacked']),
    );

    expect($affected)->toBe(0);

    $name = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('ticket_types')->where('id', TicketTypeFixture::TICKET_TYPE_B)->value('name'),
    );

    expect($name)->not->toBe('Hijacked');
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('ticket_types')->where('id', TicketTypeFixture::TICKET_TYPE_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('ticket_types')->where('id', TicketTypeFixture::TICKET_TYPE_B)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('rejects an insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('ticket_types')->insert(validTicketTypeRow(['tenant_id' => TenantFixture::TENANT_B])),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('rejects an insert violating the price_amount non-negative CHECK regardless of tenant context', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('ticket_types')->insert(validTicketTypeRow(['price_amount' => -1])),
    ))->toThrow(QueryException::class, 'ticket_types_price_amount_non_negative');
});

it('rejects an insert violating the sales window CHECK regardless of tenant context', function () {
    $salesStart = now()->addWeek();

    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('ticket_types')->insert(validTicketTypeRow([
            'sales_start' => $salesStart,
            'sales_end' => (clone $salesStart)->subHour(),
        ])),
    ))->toThrow(QueryException::class, 'ticket_types_sales_window');
});

it('allows an insert with only one side of the sales window set', function () {
    $id = Str::uuid7()->toString();

    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('ticket_types')->insert(validTicketTypeRow(['id' => $id, 'sales_start' => now()])),
    );

    expect($affected)->toBeTrue();

    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, fn () => DB::table('ticket_types')->where('id', $id)->delete());
});

it('isolates a raw sql query that bypasses all eloquent scoping', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::select('select * from ticket_types'),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe(TicketTypeFixture::TICKET_TYPE_A);
});

it('lets nodia_platform read every tenant\'s ticket types without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('ticket_types')->pluck('id'));

    expect($ids->all())->toContain(TicketTypeFixture::TICKET_TYPE_A, TicketTypeFixture::TICKET_TYPE_B);
});

it('rejects a nodia_platform write with no tenant asserted, ticket_types has no platform write policy', function () {
    $affected = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('ticket_types')->where('id', TicketTypeFixture::TICKET_TYPE_A)->update(['name' => 'Hijacked']),
    );

    expect($affected)->toBe(0);
});
