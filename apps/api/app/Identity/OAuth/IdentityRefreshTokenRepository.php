<?php

namespace App\Identity\OAuth;

use App\Identity\Actions\RevokeRefreshTokenFamily;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Bridge\RefreshTokenRepository as PassportRefreshTokenRepository;
use Laravel\Passport\Events\RefreshTokenCreated;
use Laravel\Passport\Passport;
use Laravel\Passport\RefreshToken as PassportRefreshToken;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * Passport's own RefreshTokenRepository has no reuse-detection concept
 * beyond a boolean revoked flag, and its revoke is an unconditional
 * UPDATE with no affected-row guard (stage-03 task-01 journal). This
 * subclass adds family-scoped reuse detection: every refresh token
 * issued from one login, and every token it rotates into, shares a
 * family_id, and presenting an already-revoked family member revokes
 * every other live token in the family. It also turns the unconditional
 * revoke into a conditional UPDATE checked by affected-row count, so
 * concurrent rotation of one token succeeds exactly once (stage-03 plan,
 * Slice 2).
 *
 * Bound in IdentityServiceProvider against Bridge\RefreshTokenRepository's
 * own class name, not the interface: PassportServiceProvider resolves
 * that concrete class through the container for both the password grant
 * (initial login) and the refresh grant (rotation), so one container
 * binding reaches every code path that creates or rotates a refresh
 * token.
 */
final class IdentityRefreshTokenRepository extends PassportRefreshTokenRepository
{
    /**
     * league/oauth2-server's own hint text for an already-revoked refresh
     * token (League\OAuth2\Server\Grant\RefreshTokenGrant::validateOldRefreshToken()).
     * Pinned here as the signal that isRefreshTokenRevoked() below is what
     * produced the error, as distinct from an expired or malformed token,
     * which carry the vendor's other hint texts, or the rotation race
     * below, which carries a hint this class controls. If a future
     * league/oauth2-server release changes this string, the corresponding
     * branch in App\Identity\Actions\RotateStaffToken degrades to the
     * generic invalid_refresh_token code instead of misrouting to
     * refresh_token_reused, and the feature test asserting the reused-token
     * response would catch the drift.
     */
    public const string VendorRevokedHint = 'Token has been revoked';

    /**
     * This class's own hint for the rotation guard's loser: a legitimate
     * race (a retried request, a doubled client call), not detected
     * reuse, so it must not be confused with VendorRevokedHint above and
     * must not revoke the family, which would punish the race's winner
     * for a brand-new token pair it did nothing wrong to earn.
     */
    public const string RaceLostHint = 'Refresh token rotation lost to a concurrent request';

    /**
     * The family_id of the token being rotated away from lives on a
     * request-scoped RefreshTokenRotationContext, not a property here: this
     * repository is captured by Passport's singleton AuthorizationServer
     * and, under Octane, outlives the request that created it, so a failed
     * rotation (revoke succeeded, but issuance threw before persist could
     * clear the field) would otherwise leak the family into the next
     * request the worker handled. The scoped context is reset at each
     * request boundary; revokeRefreshToken() writes it and the very next
     * persistNewRefreshToken() consumes and clears it, both once and in
     * that order per refresh request (verified against
     * League\OAuth2\Server\Grant\RefreshTokenGrant::respondToAccessTokenRequest).
     */
    public function __construct(Dispatcher $events)
    {
        parent::__construct($events);
    }

    /**
     * family_id is not part of Passport's own schema, so
     * parent::persistNewRefreshToken() cannot carry it, and family_id is
     * NOT NULL: a follow-up UPDATE after an insert without it would fail
     * the constraint before ever running. This mirrors the parent's own
     * insert (Laravel\Passport\Bridge\RefreshTokenRepository) with
     * family_id added to the same forceFill, so the row is correct from
     * its first write. A brand-new login (no rotation family) gets a
     * fresh uuid, never the token's own identifier: refresh token ids are
     * league/oauth2-server's own 40-character hex strings, not valid
     * uuids, and family_id is typed uuid.
     */
    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        // Read and clear the rotation family before any fallible work: if
        // the save or dispatch below throws, the scoped context must not
        // retain a family that a later call on this instance would reuse.
        $rotation = app(RefreshTokenRotationContext::class);
        $familyId = $rotation->familyId ?? (string) Str::uuid();
        $rotation->familyId = null;

        Passport::refreshToken()->forceFill([
            'id' => $id = $refreshTokenEntity->getIdentifier(),
            'access_token_id' => $accessTokenId = $refreshTokenEntity->getAccessToken()->getIdentifier(),
            'family_id' => $familyId,
            'revoked' => false,
            'expires_at' => $refreshTokenEntity->getExpiryDateTime(),
        ])->save();

        $this->events->dispatch(new RefreshTokenCreated($id, $accessTokenId));
    }

    /**
     * The rotation guard (stage-03 plan, Slice 2 Concurrency): a
     * conditional UPDATE on revoked, checked by affected-row count, never
     * read-then-write. Two parallel rotations of the same token race this
     * statement; the loser's affected-row count is 0, and it fails the
     * request instead of silently letting both requests mint a token pair
     * from the same refresh token. The family_id lookup below runs only
     * after the guard has already decided the outcome, purely to carry
     * the winning token's family forward to persistNewRefreshToken(), so
     * it never participates in the guard's own decision.
     */
    public function revokeRefreshToken(string $tokenId): void
    {
        $affected = PassportRefreshToken::query()
            ->whereKey($tokenId)
            ->where('revoked', false)
            ->update(['revoked' => true]);

        if ($affected === 0) {
            throw OAuthServerException::invalidRefreshToken(self::RaceLostHint);
        }

        app(RefreshTokenRotationContext::class)->familyId = PassportRefreshToken::query()
            ->whereKey($tokenId)
            ->value('family_id');
    }

    /**
     * Reuse detection: a token that is already revoked by the time it is
     * presented again was either legitimately rotated away from (the
     * common case) or is being replayed, e.g. by an attacker who captured
     * it before its legitimate rotation. The revoked flag alone cannot
     * distinguish the two, so every presentation of an already-revoked
     * token revokes its whole family; a legitimate holder who rotated
     * normally is unaffected, because their own request already moved
     * them onto the new token, not the stale one being replayed.
     */
    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        $this->lockStaffIdentity($tokenId);

        $token = PassportRefreshToken::query()->whereKey($tokenId)->first(['revoked', 'family_id']);

        if ($token === null) {
            return true;
        }

        if (! $token->revoked) {
            return false;
        }

        app(RevokeRefreshTokenFamily::class)((string) $token->family_id);

        return true;
    }

    /**
     * Staff refresh actions run inside a transaction. Locking their user
     * before the grant validates or replaces the old pair serializes the
     * whole rotation against password reset's matching user-row lock.
     */
    private function lockStaffIdentity(string $tokenId): void
    {
        if (DB::transactionLevel() === 0) {
            return;
        }

        $userId = DB::table('oauth_refresh_tokens')
            ->join('oauth_access_tokens', 'oauth_access_tokens.id', '=', 'oauth_refresh_tokens.access_token_id')
            ->join('oauth_clients', 'oauth_clients.id', '=', 'oauth_access_tokens.client_id')
            ->where('oauth_refresh_tokens.id', $tokenId)
            ->where('oauth_clients.provider', 'users')
            ->value('oauth_access_tokens.user_id');

        if ($userId !== null) {
            User::query()->whereKey($userId)->lockForUpdate()->first();
        }
    }
}
