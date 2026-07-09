<?php

namespace App\Identity\OAuth;

use Laravel\Passport\Bridge\AccessToken;
use Laravel\Passport\Bridge\Client as PassportClient;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use RuntimeException;

/**
 * Passport::useAccessTokenEntity() requires a class-string<Bridge\AccessToken>
 * (enforced by Passport's own type declaration), so this extends it rather
 * than implementing AccessTokenEntityInterface directly. Extending alone is
 * not enough to customize claims: AccessTokenTrait::convertToJWT() and the
 * $privateKey/$jwtConfiguration state it needs are private to whichever
 * class composes the trait, so a plain subclass has no way to reach them
 * (confirmed against vendor/league/oauth2-server source; stage-03 task-01
 * journal). Re-composing AccessTokenTrait here gives this class its own
 * private copies of that state, shadowing the parent's inaccessible ones;
 * the other trait members it needs (getClient(), getIdentifier(), ...) stay
 * inherited from Bridge\AccessToken, whose own constructor this class does
 * not need to override.
 */
class IdentityAccessToken extends AccessToken
{
    use AccessTokenTrait;

    public function toString(): string
    {
        $this->initJwtConfiguration();

        $builder = $this->jwtConfiguration->builder()
            ->permittedFor($this->getClient()->getIdentifier())
            ->identifiedBy($this->getIdentifier())
            ->issuedAt(now())
            ->canOnlyBeUsedAfter(now())
            ->expiresAt($this->getExpiryDateTime())
            ->relatedTo($this->getSubjectIdentifier())
            ->withClaim('scopes', $this->getScopes());

        foreach (IdentityClaims::for($this->providerName()) as $claim => $value) {
            $builder = $builder->withClaim($claim, $value);
        }

        return $builder
            ->getToken($this->jwtConfiguration->signer(), $this->jwtConfiguration->signingKey())
            ->toString();
    }

    private function providerName(): string
    {
        $client = $this->getClient();

        if (! $client instanceof PassportClient || $client->provider === null) {
            throw new RuntimeException('OAuth client has no bound provider; cannot resolve identity claims.');
        }

        return $client->provider;
    }
}
