<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\Identity\Models\Customer;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Builds on TenantFixture's two tenants with one customer row per tenant,
 * the two-tenant fixture stage-03 plan task breakdown item 12 names ("a
 * customer row created under tenant A is invisible and immutable under
 * tenant B"). Both rows share one email address on purpose, proving the
 * `unique(tenant_id, email)` constraint scopes by tenant rather than
 * globally (system-design 5.2: "the same email holds independent accounts
 * under different tenants"). customers carries no self-read or
 * platform-write extras (stage-03 plan Data model: "standard single-table
 * policy"), so this fixture is deliberately smaller than IdentityFixture.
 */
final class CustomerFixture
{
    public const CUSTOMER_A = '019797f0-0000-7000-8000-0000000000c1';

    public const CUSTOMER_B = '019797f0-0000-7000-8000-0000000000c2';

    public const SHARED_EMAIL = 'shared@customer-fixture.example';

    public static function seed(): void
    {
        TenantFixture::seed();

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            Customer::factory()->create([
                'id' => self::CUSTOMER_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'email' => self::SHARED_EMAIL,
            ]);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            Customer::factory()->create([
                'id' => self::CUSTOMER_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'email' => self::SHARED_EMAIL,
            ]);
        });
    }

    public static function clean(): void
    {
        // Deleted under nodia_app with each row's own tenant asserted:
        // customers has no platform write policy, so nodia_platform alone
        // could not see past its tenant_isolation policy either.
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('customers')->where('id', self::CUSTOMER_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('customers')->where('id', self::CUSTOMER_B)->delete();
        });

        TenantFixture::clean();
    }
}
