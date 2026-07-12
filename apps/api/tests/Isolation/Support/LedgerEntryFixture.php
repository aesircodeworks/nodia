<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\Support\Database\Rls;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Two dedicated persistent tenants with one fresh ledger entry each per
 * seed. The append-only trigger blocks DELETE for every role and the
 * downgraded isolation connection has no TRUNCATE privilege, so ledger
 * rows accumulate for the process like activity_log's (see
 * WebhookProcessingTest's teardown note) and the owning tenants are
 * created idempotently and never cleaned; assertions scope by the ids
 * seeded for the current test.
 */
final class LedgerEntryFixture
{
    public const TENANT_A = '019797f0-0000-7000-8000-0000000000da';

    public const TENANT_B = '019797f0-0000-7000-8000-0000000000db';

    public static string $entryA = '';

    public static string $entryB = '';

    public static function seed(): void
    {
        actingAsRole(Rls::PLATFORM_ROLE, null, function (): void {
            foreach ([self::TENANT_A => 'Ledger Tenant A', self::TENANT_B => 'Ledger Tenant B'] as $id => $name) {
                if (Tenant::query()->whereKey($id)->doesntExist()) {
                    Tenant::factory()->create(['id' => $id, 'name' => $name]);
                }
            }
        });

        self::$entryA = Str::uuid7()->toString();
        self::$entryB = Str::uuid7()->toString();

        foreach ([
            [self::$entryA, self::TENANT_A],
            [self::$entryB, self::TENANT_B],
        ] as [$id, $tenantId]) {
            actingAsRole(Rls::APP_ROLE, $tenantId, function () use ($id, $tenantId): void {
                DB::table('ledger_entries')->insert([
                    'id' => $id,
                    'tenant_id' => $tenantId,
                    'account' => 'tenant_net',
                    'direction' => 'credit',
                    'amount' => 1000,
                    'currency' => 'USD',
                    'reference_type' => 'payment',
                    'reference_id' => Str::uuid7()->toString(),
                    'source_event_id' => Str::uuid7()->toString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        }
    }
}
