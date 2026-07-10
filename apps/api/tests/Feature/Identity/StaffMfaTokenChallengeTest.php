<?php

use App\Identity\Models\MfaRecoveryCode;
use App\Models\User;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;
use Tests\Support\TotpCodes;

/*
 * Stage-03 plan, Slice 5 / task breakdown item 11, Risks: "MFA challenge
 * inside the OAuth token exchange". mfa_code travels as a third field on
 * POST /v1/auth/staff/token; a recovery code is accepted in the same
 * field and consumed atomically.
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

/**
 * @return array{0: User, 1: string, 2: list<string>} user, secret, recovery codes
 */
function mfaEnrolledUser(string $email = 'mfa-staff@example.com'): array
{
    $user = User::factory()->create(['email' => $email]);
    $token = StaffTokens::issue($user);
    $headers = ['Authorization' => 'Bearer '.$token];

    $secret = test()->postJson('/v1/auth/mfa/enrollment', [], $headers)->json('secret');
    $recoveryCodes = test()->postJson('/v1/auth/mfa/enrollment/confirm', ['code' => TotpCodes::current($secret)], $headers)
        ->json('recovery_codes');

    return [$user, $secret, $recoveryCodes];
}

it('returns mfa_required when an enrolled user omits mfa_code', function (): void {
    mfaEnrolledUser();

    $this->postJson('/v1/auth/staff/token', ['email' => 'mfa-staff@example.com', 'password' => 'password'])
        ->assertStatus(401)
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'mfa_required');
});

it('returns mfa_code_invalid for a wrong TOTP code', function (): void {
    mfaEnrolledUser();

    $this->postJson('/v1/auth/staff/token', [
        'email' => 'mfa-staff@example.com',
        'password' => 'password',
        'mfa_code' => '000000',
    ])
        ->assertStatus(401)
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'mfa_code_invalid');
});

it('issues a token pair given a valid TOTP code', function (): void {
    [, $secret] = mfaEnrolledUser();

    $this->postJson('/v1/auth/staff/token', [
        'email' => 'mfa-staff@example.com',
        'password' => 'password',
        'mfa_code' => TotpCodes::current($secret),
    ])
        ->assertOk()
        ->assertConformsToOpenApi()
        ->assertJson(['token_type' => 'Bearer']);
});

it('accepts a recovery code exactly once, then rejects the same code as mfa_code_invalid', function (): void {
    [, , $recoveryCodes] = mfaEnrolledUser();
    $recoveryCode = $recoveryCodes[0];

    $this->postJson('/v1/auth/staff/token', [
        'email' => 'mfa-staff@example.com',
        'password' => 'password',
        'mfa_code' => $recoveryCode,
    ])->assertOk()->assertConformsToOpenApi();

    $this->postJson('/v1/auth/staff/token', [
        'email' => 'mfa-staff@example.com',
        'password' => 'password',
        'mfa_code' => $recoveryCode,
    ])
        ->assertStatus(401)
        ->assertJsonPath('code', 'mfa_code_invalid');
});

it('still rejects unknown email and wrong password identically for an enrolled account, revealing nothing about MFA enrollment', function (): void {
    mfaEnrolledUser();

    $wrongPassword = $this->postJson('/v1/auth/staff/token', [
        'email' => 'mfa-staff@example.com',
        'password' => 'not-the-password',
    ]);

    $unknownEmail = $this->postJson('/v1/auth/staff/token', [
        'email' => 'ghost@example.com',
        'password' => 'password',
    ]);

    foreach ([$wrongPassword, $unknownEmail] as $response) {
        $response->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
    }
});
