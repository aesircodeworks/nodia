<?php

use App\Identity\Actions\RevokeAllUserTokens;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\RefreshToken as PassportRefreshToken;
use Laravel\Passport\Token as PassportToken;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-03 plan, Slice 8 (task breakdown item 16): "full-family token
 * revocation on reset." Unlike App\Identity\Actions\RevokeRefreshTokenFamily
 * (scoped to one refresh token family), this revokes every live access
 * token for the resetting user across every family, and every refresh
 * token issued alongside each one, mirroring
 * tests/Unit/Identity/RefreshTokenFamilyRevocationTest.php's own
 * "RevokeRefreshTokenFamily" describe block for the sibling guard.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    PassportRefreshToken::query()->delete();
    PassportToken::query()->delete();
    User::query()->delete();
});

function revokeAllTestClient(): Client
{
    return Client::query()->where('provider', 'users')->firstOrFail();
}

function revokeAllTestAccessToken(string $userId, bool $revoked = false): PassportToken
{
    $token = new PassportToken;

    $token->forceFill([
        'id' => Str::random(40),
        'user_id' => $userId,
        'client_id' => revokeAllTestClient()->getKey(),
        'revoked' => $revoked,
    ])->save();

    return $token;
}

function revokeAllTestRefreshToken(string $accessTokenId, bool $revoked = false): PassportRefreshToken
{
    $token = new PassportRefreshToken;

    $token->forceFill([
        'id' => Str::random(40),
        'access_token_id' => $accessTokenId,
        'family_id' => (string) Str::uuid(),
        'revoked' => $revoked,
        'expires_at' => now()->addDay(),
    ])->save();

    return $token;
}

it('revokes every live access token for the user, across every family, and every refresh token issued alongside each', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $firstLiveAccessToken = revokeAllTestAccessToken($user->id);
    $firstLiveRefreshToken = revokeAllTestRefreshToken($firstLiveAccessToken->id);

    $secondLiveAccessToken = revokeAllTestAccessToken($user->id);
    $secondLiveRefreshToken = revokeAllTestRefreshToken($secondLiveAccessToken->id);

    $alreadyRevokedAccessToken = revokeAllTestAccessToken($user->id, revoked: true);
    $alreadyRevokedRefreshToken = revokeAllTestRefreshToken($alreadyRevokedAccessToken->id, revoked: true);

    $otherUserAccessToken = revokeAllTestAccessToken($other->id);
    $otherUserRefreshToken = revokeAllTestRefreshToken($otherUserAccessToken->id);

    app(RevokeAllUserTokens::class)($user->id);

    expect($firstLiveAccessToken->refresh()->revoked)->toBeTrue()
        ->and($firstLiveRefreshToken->refresh()->revoked)->toBeTrue()
        ->and($secondLiveAccessToken->refresh()->revoked)->toBeTrue()
        ->and($secondLiveRefreshToken->refresh()->revoked)->toBeTrue()
        ->and($alreadyRevokedAccessToken->refresh()->revoked)->toBeTrue()
        ->and($alreadyRevokedRefreshToken->refresh()->revoked)->toBeTrue()
        ->and($otherUserAccessToken->refresh()->revoked)->toBeFalse()
        ->and($otherUserRefreshToken->refresh()->revoked)->toBeFalse();
});

it('is a no-op for a user with no live tokens', function (): void {
    $user = User::factory()->create();
    $accessToken = revokeAllTestAccessToken($user->id, revoked: true);
    $refreshToken = revokeAllTestRefreshToken($accessToken->id, revoked: true);

    app(RevokeAllUserTokens::class)($user->id);

    expect($accessToken->refresh()->revoked)->toBeTrue()
        ->and($refreshToken->refresh()->revoked)->toBeTrue();
});
