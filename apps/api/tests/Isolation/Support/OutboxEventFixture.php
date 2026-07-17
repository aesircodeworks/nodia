<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Builds on TenantFixture's two tenants with one outbox_events row per
 * tenant, the two-tenant fixture Slice 1 isolation names: tenant A cannot
 * read or write tenant B's outbox_events under the app role; the
 * cross-tenant role can read both (stage-04 plan, Slice 1). Rows are
 * seeded with raw DB::table() inserts so the suite proves the migration's
 * RLS policies and the sequence identity assignment independent of the
 * OutboxEvent model and the recorder (both land later in this stage).
 */
final class OutboxEventFixture
{
    public const EVENT_A = '019797f0-0000-7000-8000-0000000000e1';

    public const EVENT_B = '019797f0-0000-7000-8000-0000000000e2';

    public const AGGREGATE_A = '019797f0-0000-7000-8000-0000000000a1';

    public const AGGREGATE_B = '019797f0-0000-7000-8000-0000000000a2';

    public static function seed(): void
    {
        TenantFixture::seed();

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            self::insert(self::EVENT_A, TenantFixture::TENANT_A, self::AGGREGATE_A);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            self::insert(self::EVENT_B, TenantFixture::TENANT_B, self::AGGREGATE_B);
        });
    }

    public static function clean(): void
    {
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('outbox_events')->where('tenant_id', TenantFixture::TENANT_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('outbox_events')->where('tenant_id', TenantFixture::TENANT_B)->delete();
        });

        TenantFixture::clean();
    }

    private static function insert(string $id, string $tenantId, string $aggregateId): void
    {
        DB::table('outbox_events')->insert([
            'id' => $id,
            'type' => 'TestEvent',
            'tenant_id' => $tenantId,
            'aggregate_type' => 'test_aggregate',
            'aggregate_id' => $aggregateId,
            'correlation_id' => 'isolation-fixture-correlation',
            'occurred_at' => now(),
            'payload' => json_encode(['source' => 'isolation-fixture'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
