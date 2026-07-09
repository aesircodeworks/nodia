<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
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
function issueLogoutTestTokenPair(string $email): array
{
    User::query()->firstOrCreate(['email' => $email], User::factory()->raw(['email' => $email]));

    /** @var array{access_token: string, refresh_token: string, token_type: string, expires_in: int} $pair */
    $pair = test()->postJson('/v1/auth/staff/token', [
        'email' => $email,
        'password' => 'password',
    ])->json();

    return $pair;
}

describe('POST /v1/auth/staff/logout', function (): void {
    it('revokes the access token and its refresh token', function (): void {
        $pair = issueLogoutTestTokenPair('logout-staff@example.com');

        $this->postJson('/v1/auth/staff/logout', [], ['Authorization' => 'Bearer '.$pair['access_token']])
            ->assertNoContent()
            ->assertConformsToOpenApi();

        // Illuminate\Auth\AuthManager caches a resolved guard for the life
        // of the container, and the container is not rebuilt between
        // $this->postJson() calls within one test method (only between
        // test methods); without this, the "staff" guard would keep
        // returning the user it already resolved for the logout call
        // above instead of genuinely re-validating this next bearer
        // token. A real deployment never shares one container across
        // requests, so this has no production counterpart.
        Auth::forgetGuards();

        $this->getJson('/v1/me', ['Authorization' => 'Bearer '.$pair['access_token']])
            ->assertStatus(401)
            ->assertJson(['code' => 'auth.unauthenticated']);

        $this->postJson('/v1/auth/staff/refresh', ['refresh_token' => $pair['refresh_token']])
            ->assertStatus(401);
    });

    it('does not disturb another session for the same user', function (): void {
        $email = 'multi-session-staff@example.com';

        $sessionOne = issueLogoutTestTokenPair($email);

        /** @var array{access_token: string, refresh_token: string} $sessionTwo */
        $sessionTwo = test()->postJson('/v1/auth/staff/token', [
            'email' => $email,
            'password' => 'password',
        ])->json();

        $this->postJson('/v1/auth/staff/logout', [], ['Authorization' => 'Bearer '.$sessionOne['access_token']])
            ->assertNoContent();

        // See the comment on the first test in this file: without this,
        // the guard would return the user it cached while resolving the
        // logout call above rather than genuinely re-validating this
        // session's own bearer token.
        Auth::forgetGuards();

        $this->getJson('/v1/me', ['Authorization' => 'Bearer '.$sessionTwo['access_token']])
            ->assertOk();
    });

    it('is unauthenticated without a bearer token', function (): void {
        $this->postJson('/v1/auth/staff/logout')
            ->assertStatus(401)
            ->assertConformsToOpenApi()
            ->assertJson(['code' => 'auth.unauthenticated']);
    });
});
