<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds on EventFixture's one virtual event per tenant with one
 * event_search_documents row each (stage-05c plan, task breakdown item 6,
 * TDD sequencing Slice 5: "Isolation (first, failing): cross-tenant
 * access to event_search_documents fails under RLS"). Rows are seeded
 * with raw DB::table() inserts, mirroring OutboxEventFixture's own
 * precedent, so the suite proves the migration's RLS policies
 * independent of the document builder and the RefreshSearchIndex
 * consumer (both land in this stage but after this table's migration).
 * search_vector is populated with a real to_tsvector() call via
 * DB::raw() so the NOT NULL column is satisfiable without a model.
 */
final class EventSearchDocumentFixture
{
    public const DOCUMENT_A = '019797f3-0000-7000-8000-0000000000d1';

    public const DOCUMENT_B = '019797f3-0000-7000-8000-0000000000d2';

    public static function seed(): void
    {
        EventFixture::seed();

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            self::insert(self::DOCUMENT_A, TenantFixture::TENANT_A, EventFixture::EVENT_A, 'Event A');
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            self::insert(self::DOCUMENT_B, TenantFixture::TENANT_B, EventFixture::EVENT_B, 'Event B');
        });
    }

    public static function clean(): void
    {
        // Deleted under nodia_app with each row's own tenant asserted:
        // event_search_documents has no platform write policy, so
        // nodia_platform alone could not see past its tenant_isolation
        // policy either.
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('event_search_documents')->where('id', self::DOCUMENT_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('event_search_documents')->where('id', self::DOCUMENT_B)->delete();
        });

        EventFixture::clean();
    }

    private static function insert(string $id, string $tenantId, string $eventId, string $name): void
    {
        DB::table('event_search_documents')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'event_id' => $eventId,
            'locale' => 'en',
            'name' => $name,
            'description' => null,
            'search_vector' => DB::raw("to_tsvector('simple', ".DB::getPdo()->quote($name).')'),
            'event_starts_at' => now()->addWeek(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function newId(): string
    {
        return (string) Str::uuid7();
    }
}
