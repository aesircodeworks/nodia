<?php

namespace App\Identity\OAuth;

use App\Identity\Exceptions\TenantMismatchException;
use App\Identity\Models\Customer;
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

        $provider = $this->providerName();

        $claims = $provider === IdentityClaims::CustomerProvider
            ? IdentityClaims::for($provider, $this->customerTenantId())
            : IdentityClaims::for($provider);

        $builder = $this->jwtConfiguration->builder()
            ->permittedFor($this->getClient()->getIdentifier())
            ->identifiedBy($this->getIdentifier())
            ->issuedAt(now())
            ->canOnlyBeUsedAfter(now())
            ->expiresAt($this->getExpiryDateTime())
            ->relatedTo($this->getSubjectIdentifier())
            ->withClaim('scopes', $this->getScopes());

        foreach ($claims as $claim => $value) {
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

    /**
     * customers carries RLS FORCE-enabled (stage-03 task breakdown item
     * 12), so this lookup only ever resolves a row when it runs inside a
     * tenant transaction asserting that very row's own tenant. A
     * customer refresh presented against a different tenant's host (the
     * only way this could come up: token issuance and rotation both run
     * inside App\Tenancy\Http\Middleware\ResolveTenantFromHost's
     * transaction) finds nothing here and is rejected as tenant_mismatch
     * rather than leaking a raw 500 or minting a token with no tenant
     * claim; the enclosing request transaction then rolls back the
     * rotation that was about to complete, undoing it cleanly (stage-03
     * plan, Customer authentication and lifecycle).
     */
    private function customerTenantId(): string
    {
        $customer = Customer::query()->find($this->getSubjectIdentifier());

        if ($customer === null) {
            throw TenantMismatchException::make();
        }

        return $customer->tenant_id;
    }
}
