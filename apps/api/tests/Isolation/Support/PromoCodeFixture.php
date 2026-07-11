<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\Orders\Models\PromoCode;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;

/**
 * One percentage promo code per tenant over TenantFixture (stage-07
 * plan, task breakdown item 11), mirroring HoldFixture's shape. The
 * codes share the same code string on purpose: uniqueness is
 * (tenant_id, code), never global.
 */
final class PromoCodeFixture
{
    public const PROMO_A = '019797f5-0000-7000-8000-0000000000a1';

    public const PROMO_B = '019797f5-0000-7000-8000-0000000000a2';

    public const SHARED_CODE = 'SHARED10';

    public static function seed(): void
    {
        TenantFixture::seed();

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            PromoCode::factory()->create([
                'id' => self::PROMO_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'code' => self::SHARED_CODE,
            ]);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            PromoCode::factory()->create([
                'id' => self::PROMO_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'code' => self::SHARED_CODE,
            ]);
        });
    }

    public static function clean(): void
    {
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('promo_codes')->where('id', self::PROMO_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('promo_codes')->where('id', self::PROMO_B)->delete();
        });

        TenantFixture::clean();
    }
}
