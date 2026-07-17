<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\Payments\Enums\PayoutStatus;
use App\Payments\Models\Payout;
use App\Support\Database\Rls;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Builds on TenantFixture with one pending payout per tenant, both on the
 * fake gateway. payouts carries the standard single-table policy with no
 * platform-write extras, mirroring SubmerchantAccountFixture's shape
 * (stage-08c plan, Data model "payouts").
 */
final class PayoutFixture
{
    public const PAYOUT_A = '019797f5-0000-7000-8000-0000000000e1';

    public const PAYOUT_B = '019797f5-0000-7000-8000-0000000000e2';

    public static function seed(): void
    {
        TenantFixture::seed();

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            Payout::factory()->create([
                'id' => self::PAYOUT_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'gateway' => 'fake',
                'gateway_reference' => 'payout_a',
                'money' => Money::of(5000, 'USD'),
                'status' => PayoutStatus::Pending,
            ]);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            Payout::factory()->create([
                'id' => self::PAYOUT_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'gateway' => 'fake',
                'gateway_reference' => 'payout_b',
                'money' => Money::of(5000, 'USD'),
                'status' => PayoutStatus::Pending,
            ]);
        });
    }

    public static function clean(): void
    {
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('payouts')->where('id', self::PAYOUT_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('payouts')->where('id', self::PAYOUT_B)->delete();
        });

        TenantFixture::clean();
    }
}
