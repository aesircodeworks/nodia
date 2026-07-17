<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Builds on OutboxEventFixture with one pending outbox_deliveries row per
 * tenant, the two-tenant fixture Slice 2 isolation names: tenant A cannot
 * read or write tenant B's deliveries under the app role; the
 * cross-tenant role can read both (stage-04 plan, Slice 2 / task 6). Rows
 * are seeded with raw DB::table() inserts so the suite proves the
 * migration's RLS policies independent of the OutboxDelivery model and
 * later dispatcher/job code.
 */
final class OutboxDeliveryFixture
{
    public const DELIVERY_A = '019797f0-0000-7000-8000-0000000000d1';

    public const DELIVERY_B = '019797f0-0000-7000-8000-0000000000d2';

    public const SUBSCRIBER = 'isolation_test_subscriber';

    public static function seed(): void
    {
        OutboxEventFixture::seed();

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            self::insert(self::DELIVERY_A, OutboxEventFixture::EVENT_A, TenantFixture::TENANT_A);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            self::insert(self::DELIVERY_B, OutboxEventFixture::EVENT_B, TenantFixture::TENANT_B);
        });
    }

    public static function clean(): void
    {
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('outbox_deliveries')->where('tenant_id', TenantFixture::TENANT_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('outbox_deliveries')->where('tenant_id', TenantFixture::TENANT_B)->delete();
        });

        OutboxEventFixture::clean();
    }

    private static function insert(string $id, string $eventId, string $tenantId): void
    {
        DB::table('outbox_deliveries')->insert([
            'id' => $id,
            'outbox_event_id' => $eventId,
            'tenant_id' => $tenantId,
            'subscriber' => self::SUBSCRIBER,
            'status' => 'pending',
            'processed_at' => null,
            'last_enqueued_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
