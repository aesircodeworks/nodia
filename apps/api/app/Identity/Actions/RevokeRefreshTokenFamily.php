<?php

namespace App\Identity\Actions;

use Laravel\Passport\RefreshToken as PassportRefreshToken;
use Laravel\Passport\Token as PassportToken;

/**
 * Revokes every live token in a refresh token family: every refresh token
 * sharing the family id, and the access token each of them was issued
 * alongside, whether or not either has been presented since. Triggered by
 * App\Identity\OAuth\IdentityRefreshTokenRepository when an already-revoked
 * refresh token is presented again (stage-03 plan, Slice 2; Risks "Reuse
 * detection semantics"). Both UPDATEs are conditioned on revoked = false,
 * so they are naturally idempotent: replaying the same stale token, or a
 * concurrent replay of it, triggers this safely more than once.
 */
final class RevokeRefreshTokenFamily
{
    public function __invoke(string $familyId): void
    {
        $liveAccessTokenIds = PassportRefreshToken::query()
            ->where('family_id', $familyId)
            ->where('revoked', false)
            ->pluck('access_token_id');

        if ($liveAccessTokenIds->isEmpty()) {
            return;
        }

        PassportRefreshToken::query()
            ->where('family_id', $familyId)
            ->where('revoked', false)
            ->update(['revoked' => true]);

        PassportToken::query()
            ->whereIn('id', $liveAccessTokenIds)
            ->where('revoked', false)
            ->update(['revoked' => true]);
    }
}
