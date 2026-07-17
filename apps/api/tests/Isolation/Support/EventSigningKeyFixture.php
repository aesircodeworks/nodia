<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\Orders\Enums\SigningKeyStatus;
use App\Orders\Models\EventSigningKey;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Builds on EventFixture with one active signing key per tenant
 * (stage-09 plan, Data model "event_signing_keys").
 */
final class EventSigningKeyFixture
{
    public const KEY_A = '019797f7-0000-7000-8000-0000000000c1';

    public const KEY_B = '019797f7-0000-7000-8000-0000000000c2';

    public static function seed(): void
    {
        EventFixture::seed();

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            EventSigningKey::factory()->create([
                'id' => self::KEY_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'event_id' => EventFixture::EVENT_A,
                'key_version' => 1,
                'status' => SigningKeyStatus::Active,
            ]);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            EventSigningKey::factory()->create([
                'id' => self::KEY_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'event_id' => EventFixture::EVENT_B,
                'key_version' => 1,
                'status' => SigningKeyStatus::Active,
            ]);
        });
    }

    public static function clean(): void
    {
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('event_signing_keys')->where('id', self::KEY_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('event_signing_keys')->where('id', self::KEY_B)->delete();
        });

        EventFixture::clean();
    }
}
