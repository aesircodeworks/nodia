<?php

namespace App\Identity\Actions;

use Laravel\Passport\RefreshToken as PassportRefreshToken;
use Laravel\Passport\Token as PassportToken;

/**
 * Revokes every live access token belonging to a user, across every
 * refresh token family, and every refresh token issued alongside each one
 * (stage-03 plan, task breakdown item 16: "revocation of every live
 * access and refresh token for the user" on password reset). Broader than
 * App\Identity\Actions\RevokeRefreshTokenFamily, which scopes to one
 * family on detected refresh-token reuse: a password reset must end every
 * session the compromised or forgotten password could have started,
 * whichever family it belongs to. Both UPDATEs are conditioned on revoked
 * = false, so they are naturally idempotent.
 */
final class RevokeAllUserTokens
{
    public function __invoke(string $userId): void
    {
        $liveAccessTokenIds = PassportToken::query()
            ->where('user_id', $userId)
            ->where('revoked', false)
            ->pluck('id');

        if ($liveAccessTokenIds->isEmpty()) {
            return;
        }

        PassportToken::query()
            ->whereIn('id', $liveAccessTokenIds)
            ->where('revoked', false)
            ->update(['revoked' => true]);

        PassportRefreshToken::query()
            ->whereIn('access_token_id', $liveAccessTokenIds)
            ->where('revoked', false)
            ->update(['revoked' => true]);
    }
}
