<?php

use App\Models\User;
use Defuse\Crypto\Crypto;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Passport;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    User::query()->delete();
});

/**
 * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
 */
function issueStaffTokenPair(string $email = 'staff@example.com'): array
{
    User::factory()->create(['email' => $email]);

    /** @var array{access_token: string, refresh_token: string, token_type: string, expires_in: int} $pair */
    $pair = test()->postJson('/v1/auth/staff/token', [
        'email' => $email,
        'password' => 'password',
    ])->json();

    return $pair;
}

/**
 * Decrypts a real refresh token, moves its expire_time into the past, and
 * re-encrypts it with the same scheme league/oauth2-server uses
 * (League\OAuth2\Server\ResponseTypes\BearerTokenResponse,
 * League\OAuth2\Server\CryptTrait). expire_time is checked with plain PHP
 * time(), never through Carbon (confirmed against vendor source before
 * writing this, mirroring the stage-03 task-02 journal's access-token
 * expiry workaround), so freezeTime()/travel() cannot move a real refresh
 * token's expiry in either direction; this exercises the exact validation
 * branch that will reject a real token once its real TTL elapses.
 */
function expiredCopyOfRefreshToken(string $refreshToken): string
{
    $key = Passport::tokenEncryptionKey(app('encrypter'));

    /** @var array<string, mixed> $payload */
    $payload = json_decode(Crypto::decryptWithPassword($refreshToken, $key), true, flags: JSON_THROW_ON_ERROR);

    $payload['expire_time'] = now()->subMinute()->timestamp;

    return Crypto::encryptWithPassword(json_encode($payload, JSON_THROW_ON_ERROR), $key);
}

describe('POST /v1/auth/staff/refresh', function (): void {
    it('rotates the refresh token and stops the old one from working', function (): void {
        $pair = issueStaffTokenPair();

        $rotated = $this->postJson('/v1/auth/staff/refresh', ['refresh_token' => $pair['refresh_token']])
            ->assertOk()
            ->assertConformsToOpenApi();

        expect($rotated->json('access_token'))->not->toBe($pair['access_token'])
            ->and($rotated->json('refresh_token'))->not->toBe($pair['refresh_token']);

        $this->postJson('/v1/auth/staff/refresh', ['refresh_token' => $pair['refresh_token']])
            ->assertStatus(401);
    });

    it('rejects an expired refresh token with invalid_refresh_token under the fake clock', function (): void {
        $pair = issueStaffTokenPair();

        $this->postJson('/v1/auth/staff/refresh', ['refresh_token' => expiredCopyOfRefreshToken($pair['refresh_token'])])
            ->assertStatus(401)
            ->assertConformsToOpenApi()
            ->assertJson(['code' => 'invalid_refresh_token']);
    });

    it('rejects a malformed refresh token with invalid_refresh_token', function (): void {
        $this->postJson('/v1/auth/staff/refresh', ['refresh_token' => 'not-a-real-token'])
            ->assertStatus(401)
            ->assertConformsToOpenApi()
            ->assertJson(['code' => 'invalid_refresh_token']);
    });

    it('fails request validation for a missing refresh token', function (): void {
        $this->postJson('/v1/auth/staff/refresh', [])
            ->assertStatus(422)
            ->assertConformsToOpenApi()
            ->assertJson(['code' => 'request.validation_failed']);
    });

    it('revokes every live token in the family when a rotated-away refresh token is reused, so its rotated access token stops working too', function (): void {
        $pair = issueStaffTokenPair();

        /** @var array{access_token: string, refresh_token: string} $rotated */
        $rotated = $this->postJson('/v1/auth/staff/refresh', ['refresh_token' => $pair['refresh_token']])->json();

        $this->getJson('/v1/me', ['Authorization' => 'Bearer '.$rotated['access_token']])->assertOk();

        $this->postJson('/v1/auth/staff/refresh', ['refresh_token' => $pair['refresh_token']])
            ->assertStatus(401)
            ->assertConformsToOpenApi()
            ->assertJson(['code' => 'refresh_token_reused']);

        // Illuminate\Auth\AuthManager caches a resolved guard for the life
        // of the container, and the container is not rebuilt between
        // $this->getJson()/postJson() calls within one test method; without
        // this, the "staff" guard would keep returning the user cached from
        // the /v1/me call above instead of genuinely re-validating this
        // now-revoked access token. A real deployment never shares one
        // container across requests, so this has no production counterpart.
        Auth::forgetGuards();

        $this->getJson('/v1/me', ['Authorization' => 'Bearer '.$rotated['access_token']])
            ->assertStatus(401)
            ->assertJson(['code' => 'auth.unauthenticated']);

        $this->postJson('/v1/auth/staff/refresh', ['refresh_token' => $rotated['refresh_token']])
            ->assertStatus(401)
            ->assertJson(['code' => 'refresh_token_reused']);
    });
});
