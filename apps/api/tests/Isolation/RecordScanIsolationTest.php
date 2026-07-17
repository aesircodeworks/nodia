<?php

use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Orders\Enums\SigningKeyStatus;
use App\Orders\Models\EventSigningKey;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\EventFixture;
use Tests\Isolation\Support\TenantFixture;
use Tests\Isolation\Support\TicketFixture;
use Tests\Support\StaffTokens;

use function Tests\Isolation\Support\actingAsRole;

/*
 * Stage-09 plan, Slice 4: a checkin.manage staff token scoped to tenant
 * A submitting a QR payload naming tenant B's ticket and event fails,
 * proving RLS makes tenant B's event_signing_keys rows (and therefore
 * any signature verification against them) invisible to tenant A: the
 * verification Action finds no key to trial at all and classifies the
 * payload qr_signature_invalid, never leaking that the named ticket or
 * event exists.
 *
 * TicketFixture::seed() already cascades through EventFixture and
 * TenantFixture (both non-idempotent, fixed-id inserts); the tenant B
 * signing key is created directly here rather than through
 * EventSigningKeyFixture::seed(), which would re-run that same cascade
 * a second time and fail on the tenants primary key.
 */

beforeEach(function (): void {
    TicketFixture::seed();

    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
        EventSigningKey::factory()->create([
            'tenant_id' => TenantFixture::TENANT_B,
            'event_id' => EventFixture::EVENT_B,
            'key_version' => 1,
            'status' => SigningKeyStatus::Active,
        ]);
    });
});

afterEach(function (): void {
    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
        DB::table('memberships')->where('tenant_id', TenantFixture::TENANT_A)->delete();
    });

    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
        DB::table('event_signing_keys')->where('tenant_id', TenantFixture::TENANT_B)->delete();
    });

    actingAsRole(Rls::PLATFORM_ROLE, null, function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        DB::table('users')->delete();
    });

    TicketFixture::clean();
});

function issueTenantAScanToken(): string
{
    $roleId = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => Role::factory()->create([
            'tenant_id' => TenantFixture::TENANT_A,
            'name' => 'Tenant A Check-in Manager',
            'capabilities' => ['checkin.manage'],
        ])->id,
    );

    $user = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => User::factory()->create());
    $token = StaffTokens::issue($user);
    $user->forceFill(['mfa_enabled' => true, 'mfa_confirmed_at' => now()])->save();

    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function () use ($user, $roleId): void {
        Membership::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => TenantFixture::TENANT_A,
            'role_id' => $roleId,
            'scope' => MembershipScope::Tenant,
        ]);
    });

    return $token;
}

it('fails to verify a payload naming a foreign tenant ticket, never leaking its existence', function (): void {
    $token = issueTenantAScanToken();

    $payload = rtrim(strtr(base64_encode((string) json_encode([
        'ticket_id' => TicketFixture::TICKET_B,
        'event_id' => EventFixture::EVENT_B,
        'rotation' => 0,
        'signature' => hash_hmac('sha256', TicketFixture::TICKET_B.'|'.EventFixture::EVENT_B.'|0', 'guessed-secret'),
    ])), '+/', '-_'), '=');

    $response = test()->postJson('/v1/check-ins', [
        'qr_payload' => $payload,
        'device_id' => 'device-1',
        'client_scan_id' => (string) Str::uuid7(),
        'scanned_at' => now()->toIso8601String(),
    ], [
        'X-Tenant-Id' => TenantFixture::TENANT_A,
        'Authorization' => 'Bearer '.$token,
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('code', 'qr_signature_invalid');
});
