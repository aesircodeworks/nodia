<?php

use App\Identity\Models\MfaRecoveryCode;
use App\Models\User;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;
use Tests\Support\TotpCodes;

/*
 * Stage-03 plan, Slice 5 / task breakdown item 11: MFA enrollment and
 * confirmation. Staff bearer only, no X-Tenant-Id, mirroring GET
 * /v1/me's own precedent for a bearer-only route.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    // mfa_recovery_codes.user_id has no cascade, so it must be cleared
    // before the users row it belongs to (task breakdown item 10's
    // migration).
    MfaRecoveryCode::query()->delete();
    User::query()->delete();
});

function mfaBearer(): array
{
    $user = User::factory()->create();
    $token = StaffTokens::issue($user);

    return [$user, $token];
}

describe('POST /v1/auth/mfa/enrollment', function (): void {
    it('issues a fresh TOTP secret and otpauth URI', function (): void {
        [$user, $token] = mfaBearer();

        $response = $this->postJson('/v1/auth/mfa/enrollment', [], ['Authorization' => 'Bearer '.$token]);

        $response->assertOk()
            ->assertConformsToOpenApi();

        expect($response->json('secret'))->toBeString()->not->toBeEmpty()
            ->and($response->json('otpauth_uri'))->toStartWith('otpauth://totp/')
            ->and($response->json('otpauth_uri'))->toContain(rawurlencode($user->email));

        expect($user->refresh()->mfa_secret)->toBe($response->json('secret'))
            ->and($user->mfa_enabled)->toBeFalse();
    });

    it('rejects enrollment for a user who already confirmed MFA', function (): void {
        [, $token] = mfaBearer();
        $headers = ['Authorization' => 'Bearer '.$token];

        $secret = $this->postJson('/v1/auth/mfa/enrollment', [], $headers)->json('secret');
        $this->postJson('/v1/auth/mfa/enrollment/confirm', ['code' => TotpCodes::current($secret)], $headers)
            ->assertOk();

        $this->postJson('/v1/auth/mfa/enrollment', [], $headers)
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'mfa_already_enrolled');
    });

    it('is unauthenticated without a bearer token', function (): void {
        $this->postJson('/v1/auth/mfa/enrollment')
            ->assertStatus(401)
            ->assertJsonPath('code', 'auth.unauthenticated');
    });
});

describe('POST /v1/auth/mfa/enrollment/confirm', function (): void {
    it('confirms with a valid TOTP code and returns recovery codes exactly once', function (): void {
        [$user, $token] = mfaBearer();
        $headers = ['Authorization' => 'Bearer '.$token];

        $secret = $this->postJson('/v1/auth/mfa/enrollment', [], $headers)->json('secret');

        $response = $this->postJson('/v1/auth/mfa/enrollment/confirm', ['code' => TotpCodes::current($secret)], $headers);

        $response->assertOk()->assertConformsToOpenApi();

        $codes = $response->json('recovery_codes');

        expect($codes)->toBeArray()
            ->and(count($codes))->toBe(config()->integer('identity.mfa_recovery_code_count'))
            ->and(count(array_unique($codes)))->toBe(count($codes));

        $user->refresh();
        expect($user->mfa_enabled)->toBeTrue()
            ->and($user->mfa_confirmed_at)->not->toBeNull();

        // GET /v1/me now reflects mfa_enabled; the recovery codes
        // themselves are never exposed by any other endpoint.
        $this->getJson('/v1/me', $headers)->assertJsonPath('mfa_enabled', true);
    });

    it('rejects an incorrect code with mfa_code_invalid', function (): void {
        [, $token] = mfaBearer();
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->postJson('/v1/auth/mfa/enrollment', [], $headers);

        $this->postJson('/v1/auth/mfa/enrollment/confirm', ['code' => '000000'], $headers)
            ->assertStatus(401)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'mfa_code_invalid');
    });

    it('rejects confirmation when no enrollment was started', function (): void {
        [, $token] = mfaBearer();

        $this->postJson('/v1/auth/mfa/enrollment/confirm', ['code' => '000000'], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'mfa_not_enrolled');
    });

    it('fails request validation for a missing code', function (): void {
        [, $token] = mfaBearer();

        $this->postJson('/v1/auth/mfa/enrollment/confirm', [], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonPath('code', 'request.validation_failed');
    });
});
