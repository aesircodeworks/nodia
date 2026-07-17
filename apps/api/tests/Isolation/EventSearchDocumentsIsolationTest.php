<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Isolation\Support\EventFixture;
use Tests\Isolation\Support\EventSearchDocumentFixture;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * event_search_documents is the standard Rls::applyTenantPolicies posture
 * with no platform write policy (stage-05c plan, Data model: "RLS policy
 * in the same migration, same pattern as above" quoting the media
 * table's own posture; mutation here is the RefreshSearchIndex consumer
 * and search:rebuild, both running as a real tenant, never the platform
 * posture, mirroring events and ticket_types). Written first per the
 * plan's TDD sequencing, Slice 5: "Isolation (first, failing):
 * cross-tenant access to event_search_documents fails under RLS",
 * failing until the event_search_documents migration and its RLS policy
 * land. Also proves the unique (event_id, locale) constraint the
 * creating migration ships regardless of tenant context, mirroring
 * events_end_after_start/ticket_types_price_amount_non_negative's own
 * isolation coverage of the migration's other invariants.
 */

/**
 * @return array<string, mixed>
 */
function validSearchDocumentRow(array $overrides = []): array
{
    return [
        'id' => EventSearchDocumentFixture::newId(),
        'tenant_id' => TenantFixture::TENANT_A,
        'event_id' => EventFixture::EVENT_A,
        'locale' => 'fr',
        'name' => 'Forged Document',
        'description' => null,
        'search_vector' => DB::raw("to_tsvector('simple', 'Forged Document')"),
        'event_starts_at' => now()->addWeek(),
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

beforeEach(fn () => EventSearchDocumentFixture::seed());

afterEach(fn () => EventSearchDocumentFixture::clean());

it('shows a tenant only its own search document row', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('event_search_documents')->pluck('id'),
    );

    expect($ids->all())->toBe([EventSearchDocumentFixture::DOCUMENT_A]);
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('event_search_documents')
            ->where('id', EventSearchDocumentFixture::DOCUMENT_B)
            ->update(['name' => 'Hijacked']),
    );

    expect($affected)->toBe(0);

    $name = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('event_search_documents')->where('id', EventSearchDocumentFixture::DOCUMENT_B)->value('name'),
    );

    expect($name)->not->toBe('Hijacked');
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('event_search_documents')->where('id', EventSearchDocumentFixture::DOCUMENT_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('event_search_documents')->where('id', EventSearchDocumentFixture::DOCUMENT_B)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('rejects an insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('event_search_documents')->insert(validSearchDocumentRow(['tenant_id' => TenantFixture::TENANT_B])),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('rejects a second row for the same event and locale through the unique constraint regardless of tenant context', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('event_search_documents')->insert(validSearchDocumentRow(['locale' => 'en'])),
    ))->toThrow(QueryException::class, 'event_search_documents_event_id_locale_unique');
});

it('cascades the delete when the owning event is deleted', function () {
    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
        DB::table('events')->where('id', EventFixture::EVENT_A)->delete();
    });

    $exists = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('event_search_documents')->where('id', EventSearchDocumentFixture::DOCUMENT_A)->exists(),
    );

    expect($exists)->toBeFalse();
});

it('isolates a raw sql query that bypasses all eloquent scoping', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::select('select * from event_search_documents'),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe(EventSearchDocumentFixture::DOCUMENT_A);
});

it('lets nodia_platform read every tenant\'s search documents without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('event_search_documents')->pluck('id'));

    expect($ids->all())->toContain(EventSearchDocumentFixture::DOCUMENT_A, EventSearchDocumentFixture::DOCUMENT_B);
});

it('rejects a nodia_platform write with no tenant asserted, event_search_documents has no platform write policy', function () {
    $affected = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('event_search_documents')->where('id', EventSearchDocumentFixture::DOCUMENT_A)->update(['name' => 'Hijacked']),
    );

    expect($affected)->toBe(0);
});
