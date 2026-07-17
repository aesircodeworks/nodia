<?php

namespace App\Identity\OAuth;

/**
 * Carries the family_id of the refresh token being rotated away from,
 * written by IdentityRefreshTokenRepository::revokeRefreshToken() and
 * consumed by the very next persistNewRefreshToken() within the same
 * refresh request.
 *
 * Bound request-scoped ($this->app->scoped) in IdentityServiceProvider
 * rather than held as a property on the repository: Passport's
 * AuthorizationServer is a container singleton that captures one
 * repository instance and, under Octane, survives across every request a
 * worker handles. The captured repository resolves this scoped holder
 * inside each operation instead of constructor-injecting and retaining the
 * first request's instance. A failed issuance can therefore never leak its
 * family into the next Octane request.
 */
final class RefreshTokenRotationContext
{
    public ?string $familyId = null;
}
