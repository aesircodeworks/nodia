<?php

namespace App\Identity\OAuth;

use RuntimeException;

/**
 * Extra JWT claims per identity population, keyed by the auth provider name
 * (config/auth.php `providers`) the issuing OAuth client is bound to
 * (system-design 5.4: identity_type plus subject on every token, tenant_id
 * only for customers). Deliberately a pure function, decoupled from
 * league/oauth2-server and lcobucci/jwt, so the exact claim set is
 * unit-testable without a signing key or an HTTP round trip; resolving
 * $tenantId itself (a database read against the Customer model) is the
 * caller's job, App\Identity\OAuth\IdentityAccessToken, not this class's.
 */
final class IdentityClaims
{
    public const string StaffProvider = 'users';

    public const string CustomerProvider = 'customers';

    /**
     * @return array<string, mixed>
     */
    public static function for(string $provider, ?string $tenantId = null): array
    {
        return match ($provider) {
            self::StaffProvider => ['identity_type' => 'staff'],
            self::CustomerProvider => [
                'identity_type' => 'customer',
                'tenant_id' => $tenantId ?? throw new RuntimeException('Customer identity claims require a tenant id.'),
            ],
            default => throw new RuntimeException("Unknown OAuth provider [{$provider}] for identity claims."),
        };
    }
}
