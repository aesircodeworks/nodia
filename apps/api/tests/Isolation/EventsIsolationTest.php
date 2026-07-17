<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\EventFixture;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * events is the standard Rls::applyTenantPolicies posture with no extra
 * policies (stage-05a plan Data model: event mutation is tenant admin
 * surface, mirroring venues), the same shape venues already established.
 * Written first per the master plan's TDD sequencing (stage-05a plan, TDD
 * sequencing, Slice 2: "Isolation (first): same probes against events"),
 * failing until the events migration and its RLS policy land. Also
 * proves the two CHECK constraints the creating migration ships
 * (events_end_after_start, events_venue_or_url) regardless of tenant
 * context, mirroring venues_capacity_positive's own isolation coverage.
 */

/**
 * @return array<string, mixed>
 */
function validEventRow(array $overrides = []): array
{
    $startAt = now()->addWeek();

    return [
        'id' => Str::uuid7()->toString(),
        'venue_id' => null,
        'status' => 'draft',
        'name' => json_encode(['en' => 'Forged Event'], JSON_THROW_ON_ERROR),
        'description' => json_encode(['en' => 'A forged event'], JSON_THROW_ON_ERROR),
        'start_at' => $startAt,
        'end_at' => (clone $startAt)->addHours(3),
        'timezone' => 'UTC',
        'is_virtual' => true,
        'virtual_event_url' => 'https://example.test/stream',
        'async_payment_policy' => json_encode(['slow_methods_enabled' => true, 'low_inventory_cutoff' => null], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

beforeEach(fn () => EventFixture::seed());

afterEach(fn () => EventFixture::clean());

it('shows a tenant only its own event row', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('events')->pluck('id'),
    );

    expect($ids->all())->toBe([EventFixture::EVENT_A]);
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('events')
            ->where('id', EventFixture::EVENT_B)
            ->update(['status' => 'published']),
    );

    expect($affected)->toBe(0);

    $status = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('events')->where('id', EventFixture::EVENT_B)->value('status'),
    );

    expect($status)->toBe('draft');
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('events')->where('id', EventFixture::EVENT_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('events')->where('id', EventFixture::EVENT_B)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('rejects an insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('events')->insert(validEventRow(['tenant_id' => TenantFixture::TENANT_B])),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('rejects an insert violating the end_at-after-start_at CHECK regardless of tenant context', function () {
    $startAt = now()->addWeek();

    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('events')->insert(validEventRow([
            'tenant_id' => TenantFixture::TENANT_A,
            'start_at' => $startAt,
            'end_at' => (clone $startAt)->subHour(),
        ])),
    ))->toThrow(QueryException::class, 'events_end_after_start');
});

it('rejects an insert violating the venue-or-url CHECK regardless of tenant context', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('events')->insert(validEventRow([
            'tenant_id' => TenantFixture::TENANT_A,
            'is_virtual' => true,
            'venue_id' => null,
            'virtual_event_url' => null,
        ])),
    ))->toThrow(QueryException::class, 'events_venue_or_url');
});

it('isolates a raw sql query that bypasses all eloquent scoping', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::select('select * from events'),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe(EventFixture::EVENT_A);
});

it('lets nodia_platform read every tenant\'s events without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('events')->pluck('id'));

    expect($ids->all())->toContain(EventFixture::EVENT_A, EventFixture::EVENT_B);
});

it('rejects a nodia_platform write with no tenant asserted, events has no platform write policy', function () {
    $affected = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('events')->where('id', EventFixture::EVENT_A)->update(['status' => 'published']),
    );

    expect($affected)->toBe(0);
});
