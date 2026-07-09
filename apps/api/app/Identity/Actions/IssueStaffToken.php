<?php

namespace App\Identity\Actions;

use App\Identity\Data\StaffTokenRequestData;
use App\Identity\Data\TokenPairData;
use App\Identity\Exceptions\InvalidCredentialsException;
use Illuminate\Http\Request;
use Laravel\Passport\Client;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

/**
 * Drives Passport's AuthorizationServer directly with grant_type=password
 * instead of routing through Passport's own /oauth/token controller
 * (disabled in IdentityServiceProvider), so the wire contract stays
 * {email, password} rather than the raw OAuth request shape (stage-03
 * task-01 journal). league/oauth2-server's PasswordGrant maps both an
 * unknown username and a wrong password to the same invalid_grant error
 * (Laravel\Passport\Bridge\UserRepository never distinguishes the two),
 * which is exactly the no-enumeration behavior the stage-03 plan asks for.
 */
final class IssueStaffToken
{
    private const string StaffProvider = 'users';

    public function __construct(
        private readonly AuthorizationServer $server,
        private readonly ResponseInterface $blankResponse,
    ) {}

    public function __invoke(StaffTokenRequestData $data): TokenPairData
    {
        $request = Request::create('/v1/auth/staff/token', 'POST', [
            'grant_type' => 'password',
            'client_id' => $this->staffClient()->getKey(),
            'username' => $data->email,
            'password' => $data->password,
            'scope' => '',
        ]);

        try {
            $response = $this->server->respondToAccessTokenRequest(
                (new PsrHttpFactory)->createRequest($request),
                $this->blankResponse,
            );
        } catch (OAuthServerException $e) {
            // invalid_grant is the errorType league/oauth2-server's
            // PasswordGrant uses for bad credentials (its own
            // invalidCredentials() factory); anything else (an
            // unsupported grant type, a missing or misconfigured client)
            // is a real bug, not a login failure, and must not be
            // reported to the caller as one.
            if ($e->getErrorType() !== 'invalid_grant') {
                throw $e;
            }

            throw InvalidCredentialsException::becauseAuthenticationFailed();
        }

        /** @var array{access_token: string, refresh_token: string, token_type: string, expires_in: int} $payload */
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        return new TokenPairData(
            $payload['access_token'],
            $payload['refresh_token'],
            $payload['token_type'],
            $payload['expires_in'],
        );
    }

    private function staffClient(): Client
    {
        // Exactly one password-grant client is seeded per provider (task 1
        // of this stage); `password_client` is a virtual Passport
        // attribute derived from the grant_types column, not a real
        // column, so it cannot be queried at the database level.
        return Client::query()
            ->where('provider', self::StaffProvider)
            ->firstOrFail();
    }
}
