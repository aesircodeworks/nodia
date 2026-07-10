<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\Support\Database\Rls;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\DB;

/**
 * The reusable two-tenant fixture the isolation suite runs against: tenant
 * A and tenant B, one primary domain each, seeded through the platform
 * role the way production tenant provisioning runs. This supersedes the
 * Stage 1 throwaway probe tables as the shared harness later stages'
 * isolation tests build on (stage-02 plan, Slice 3); probe tables remain
 * only where the suite proves the RLS helper itself.
 */
final class TenantFixture
{
    public const TENANT_A = '019797f0-0000-7000-8000-00000000000a';

    public const TENANT_B = '019797f0-0000-7000-8000-00000000000b';

    public const DOMAIN_A = 'tenant-a.example';

    public const DOMAIN_B = 'tenant-b.example';

    public static function seed(): void
    {
        actingAsRole(Rls::PLATFORM_ROLE, null, function (): void {
            Tenant::factory()->create(['id' => self::TENANT_A, 'name' => 'Tenant A']);
            Tenant::factory()->create(['id' => self::TENANT_B, 'name' => 'Tenant B']);

            TenantDomain::factory()->create([
                'tenant_id' => self::TENANT_A,
                'domain' => self::DOMAIN_A,
                'is_primary' => true,
            ]);
            TenantDomain::factory()->create([
                'tenant_id' => self::TENANT_B,
                'domain' => self::DOMAIN_B,
                'is_primary' => true,
            ]);
        });
    }

    public static function clean(): void
    {
        // outbox_events / outbox_deliveries FK to tenants and have no platform
        // write policy, so they must be removed under nodia_app before the
        // tenant rows can go (DomainVerified and other producers pin the
        // owning tenant in the envelope).
        foreach ([self::TENANT_A, self::TENANT_B] as $tenantId) {
            actingAsRole(Rls::APP_ROLE, $tenantId, function () use ($tenantId): void {
                DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
                DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
            });
        }

        actingAsRole(Rls::PLATFORM_ROLE, null, function (): void {
            DB::table('tenant_domains')->delete();
            DB::table('tenants')->where('id', '!=', config('tenancy.platform_tenant_id'))->delete();
        });
    }
}

