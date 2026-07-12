<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\Payments\Enums\RefundStatus;
use App\Payments\Models\Refund;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Builds on PaymentFixture with one pending refund per tenant. refunds
 * carries the standard single-table policy with no platform-write
 * extras, mirroring PaymentFixture's shape (stage-08b plan, Data model
 * "refunds").
 */
final class RefundFixture
{
    public const REFUND_A = '019797f5-0000-7000-8000-0000000000c1';

    public const REFUND_B = '019797f5-0000-7000-8000-0000000000c2';

    public static function seed(): void
    {
        PaymentFixture::seed();

        foreach ([
            [self::REFUND_A, TenantFixture::TENANT_A, PaymentFixture::PAYMENT_A, 'refund-fixture-key-a'],
            [self::REFUND_B, TenantFixture::TENANT_B, PaymentFixture::PAYMENT_B, 'refund-fixture-key-b'],
        ] as [$id, $tenantId, $paymentId, $key]) {
            actingAsRole(Rls::APP_ROLE, $tenantId, function () use ($id, $tenantId, $paymentId, $key): void {
                Refund::factory()->create([
                    'id' => $id,
                    'tenant_id' => $tenantId,
                    'payment_id' => $paymentId,
                    'idempotency_key' => $key,
                    'status' => RefundStatus::Pending,
                ]);
            });
        }
    }

    public static function clean(): void
    {
        foreach ([
            [TenantFixture::TENANT_A, self::REFUND_A],
            [TenantFixture::TENANT_B, self::REFUND_B],
        ] as [$tenantId, $id]) {
            actingAsRole(Rls::APP_ROLE, $tenantId, function () use ($id): void {
                DB::table('refunds')->where('id', $id)->delete();
            });
        }

        PaymentFixture::clean();
    }
}
