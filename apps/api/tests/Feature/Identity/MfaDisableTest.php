<?php

use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\MfaRecoveryCode;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;
use Tests\Support\TotpCodes;

/*
 * Stage-03 plan, Slice 5 / task breakdown item 11: POST /v1/auth/mfa/disable.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    if (isset($this->tenantId)) {
        app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
            DB::table('memberships')->delete();
        });
    }

    // The platform-scope enforcement case assigns a sentinel-tenant
    // membership and role; always cleared, unconditionally like the
    // tenant-scope case above, rather than gated on $this->tenantId.
    app(TenantTransaction::class)->asTenant($sentinel, function (): void {
        DB::table('memberships')->delete();
    });

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });

    // mfa_recovery_codes.user_id has no cascade, so it must be cleared
    // before the users row it belongs to (task breakdown item 10's
    // migration).
    MfaRecoveryCode::query()->delete();
    User::query()->delete();
});

/**
 * @return array{0: User, 1: string, 2: string} user, bearer, mfa secret
 */
function enrolledStaff(): array
{
    $user = User::factory()->create();
    $token = StaffTokens::issue($user);
    $headers = ['Authorization' => 'Bearer '.$token];

    $secret = test()->postJson('/v1/auth/mfa/enrollment', [], $headers)->json('secret');
    test()->postJson('/v1/auth/mfa/enrollment/confirm', ['code' => TotpCodes::current($secret)], $headers);

    return [$user, $token, $secret];
}

describe('POST /v1/auth/mfa/disable', function (): void {
    it('disables MFA with a valid TOTP code', function (): void {
        [$user, $token, $secret] = enrolledStaff();

        $this->postJson('/v1/auth/mfa/disable', ['code' => TotpCodes::current($secret)], ['Authorization' => 'Bearer '.$token])
            ->assertNoContent()
            ->assertConformsToOpenApi();

        $user->refresh();
        expect($user->mfa_enabled)->toBeFalse()
            ->and($user->mfa_secret)->toBeNull()
            ->and($user->mfa_confirmed_at)->toBeNull();
    });

    it('rejects a wrong code with mfa_code_invalid', function (): void {
        [, $token] = enrolledStaff();

        $this->postJson('/v1/auth/mfa/disable', ['code' => '000000'], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(401)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'mfa_code_invalid');
    });

    it('rejects disabling when MFA was never enrolled', function (): void {
        $user = User::factory()->create();
        $token = StaffTokens::issue($user);

        $this->postJson('/v1/auth/mfa/disable', ['code' => '000000'], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'mfa_not_enrolled');
    });

    it('denies disabling for a platform-scope caller with mfa_enforced_for_role', function (): void {
        [$user, $token, $secret] = enrolledStaff();

        $sentinel = config()->string('tenancy.platform_tenant_id');
        app(TenantTransaction::class)->asPlatform(function () use ($user, $sentinel): void {
            Membership::factory()->platform()->create([
                'user_id' => $user->id,
                'role_id' => Role::factory()->create(['tenant_id' => $sentinel])->id,
            ]);
        });

        $this->postJson('/v1/auth/mfa/disable', ['code' => TotpCodes::current($secret)], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'mfa_enforced_for_role');
    });

    it('denies disabling for a tenant-scope caller whose role holds orders.refund with mfa_enforced_for_role', function (): void {
        [$user, $token, $secret] = enrolledStaff();

        $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

        app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($user): void {
            Membership::factory()->create([
                'user_id' => $user->id,
                'tenant_id' => $this->tenantId,
                'role_id' => Role::factory()->create(['tenant_id' => $this->tenantId, 'capabilities' => ['orders.refund']])->id,
                'scope' => MembershipScope::Tenant,
            ]);
        });

        $this->postJson('/v1/auth/mfa/disable', ['code' => TotpCodes::current($secret)], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403)
            ->assertJsonPath('code', 'mfa_enforced_for_role');
    });

    it('is unauthenticated without a bearer token', function (): void {
        $this->postJson('/v1/auth/mfa/disable', ['code' => '000000'])
            ->assertStatus(401)
            ->assertJsonPath('code', 'auth.unauthenticated');
    });
});
