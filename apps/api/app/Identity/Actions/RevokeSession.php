<?php

namespace App\Identity\Actions;

use Laravel\Passport\AccessToken;
use Laravel\Passport\RefreshToken as PassportRefreshToken;

/**
 * Logout revokes exactly the presented session's access token and its
 * live refresh token, never the wider family (stage-03 plan, Slice 2):
 * family-wide revocation is reserved for detected reuse
 * (RevokeRefreshTokenFamily), so a legitimate logout never cascades into
 * revoking sessions it never touched. Guard-agnostic (an AccessToken row
 * carries no identity_type of its own), so both StaffLogoutController and
 * CustomerLogoutController (stage-03 plan, task breakdown item 13) share
 * this one Action rather than duplicating identical logic per population.
 */
final class RevokeSession
{
    public function __invoke(AccessToken $accessToken): void
    {
        $accessTokenId = (string) $accessToken->oauth_access_token_id;

        $accessToken->revoke();

        PassportRefreshToken::query()
            ->where('access_token_id', $accessTokenId)
            ->where('revoked', false)
            ->update(['revoked' => true]);
    }
}
