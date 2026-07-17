<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\Payments\Enums\PaymentStatus;
use App\Payments\Models\Payment;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Builds on OrderFixture with one initiated payment per tenant. payments
 * carries the standard single-table policy with no platform-write
 * extras, mirroring OrderFixture's shape (stage-08a plan, Slice 2).
 */
final class PaymentFixture
{
    public const PAYMENT_A = '019797f5-0000-7000-8000-0000000000a1';

    public const PAYMENT_B = '019797f5-0000-7000-8000-0000000000a2';

    public static function seed(): void
    {
        OrderFixture::seed();

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            Payment::factory()->create([
                'id' => self::PAYMENT_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'order_id' => OrderFixture::ORDER_A,
                'idempotency_key' => 'payment-fixture-key-a',
                'gateway_reference' => 'fake_'.self::PAYMENT_A,
                'status' => PaymentStatus::Initiated,
            ]);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            Payment::factory()->create([
                'id' => self::PAYMENT_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'order_id' => OrderFixture::ORDER_B,
                'idempotency_key' => 'payment-fixture-key-b',
                'gateway_reference' => 'fake_'.self::PAYMENT_B,
                'status' => PaymentStatus::Initiated,
            ]);
        });
    }

    public static function clean(): void
    {
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('payments')->where('id', self::PAYMENT_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('payments')->where('id', self::PAYMENT_B)->delete();
        });

        OrderFixture::clean();
    }
}
