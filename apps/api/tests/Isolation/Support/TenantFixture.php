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
        actingAsRole(Rls::PLATFORM_ROLE, null, function (): void {
            DB::table('tenant_domains')->delete();
            DB::table('tenants')->where('id', '!=', config('tenancy.platform_tenant_id'))->delete();
        });
    }
}
