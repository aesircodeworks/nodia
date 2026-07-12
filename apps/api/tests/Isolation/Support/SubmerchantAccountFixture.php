<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\Payments\Enums\SubmerchantStatus;
use App\Payments\Models\SubmerchantAccount;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Builds on TenantFixture with one pending sub-merchant account per
 * tenant, both on the fake gateway. submerchant_accounts carries the
 * standard single-table policy with no platform-write extras, mirroring
 * PaymentFixture's shape (stage-08c plan, Data model
 * "submerchant_accounts").
 */
final class SubmerchantAccountFixture
{
    public const ACCOUNT_A = '019797f5-0000-7000-8000-0000000000d1';

    public const ACCOUNT_B = '019797f5-0000-7000-8000-0000000000d2';

    public static function seed(): void
    {
        TenantFixture::seed();

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            SubmerchantAccount::factory()->create([
                'id' => self::ACCOUNT_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'gateway' => 'fake',
                'status' => SubmerchantStatus::Pending,
            ]);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            SubmerchantAccount::factory()->create([
                'id' => self::ACCOUNT_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'gateway' => 'fake',
                'status' => SubmerchantStatus::Pending,
            ]);
        });
    }

    public static function clean(): void
    {
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('submerchant_accounts')->where('id', self::ACCOUNT_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('submerchant_accounts')->where('id', self::ACCOUNT_B)->delete();
        });

        TenantFixture::clean();
    }
}
