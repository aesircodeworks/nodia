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
 * worker handles. If token issuance throws after revoke set the family
 * but before persist could clear it (a persistence blip, a listener
 * throwing), a property on that captured repository would leak the family
 * into the next request the worker handled. A scoped binding is reset at
 * each request boundary, so the leak cannot cross requests; the repository
 * resolves this holder fresh per request instead of remembering the value
 * itself.
 */
final class RefreshTokenRotationContext
{
    public ?string $familyId = null;
}
