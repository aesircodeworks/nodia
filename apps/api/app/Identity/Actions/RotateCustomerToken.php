<?php

namespace App\Identity\Actions;

use App\Identity\Data\RefreshTokenRequestData;
use App\Identity\Data\TokenPairData;
use App\Identity\Exceptions\InvalidRefreshTokenException;
use App\Identity\Exceptions\RefreshTokenReusedException;
use App\Identity\OAuth\IdentityRefreshTokenRepository;
use Illuminate\Http\Request;
use Laravel\Passport\Client;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

/**
 * Mirrors App\Identity\Actions\RotateStaffToken exactly, bound to the
 * customers-provider client instead: rotation, reuse detection, and
 * family revocation all live in the guard-agnostic
 * App\Identity\OAuth\IdentityRefreshTokenRepository (stage-03 task-01/02
 * journals), so nothing about this class is customer-specific beyond
 * which seeded client it drives the grant through.
 */
final class RotateCustomerToken
{
    private const string CustomerProvider = 'customers';

    public function __construct(
        private readonly AuthorizationServer $server,
        private readonly ResponseInterface $blankResponse,
    ) {}

    public function __invoke(RefreshTokenRequestData $data): TokenPairData
    {
        $request = Request::create('/v1/auth/customer/refresh', 'POST', [
            'grant_type' => 'refresh_token',
            'client_id' => $this->customerClient()->getKey(),
            'refresh_token' => $data->refreshToken,
            'scope' => '',
        ]);

        try {
            $response = $this->server->respondToAccessTokenRequest(
                (new PsrHttpFactory)->createRequest($request),
                $this->blankResponse,
            );
        } catch (OAuthServerException $e) {
            if ($e->getErrorType() !== 'invalid_grant') {
                throw $e;
            }

            if ($e->getHint() === IdentityRefreshTokenRepository::VendorRevokedHint) {
                throw RefreshTokenReusedException::becauseTheFamilyWasRevoked();
            }

            throw InvalidRefreshTokenException::becauseTheTokenIsInvalid();
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

    private function customerClient(): Client
    {
        return Client::query()
            ->where('provider', self::CustomerProvider)
            ->firstOrFail();
    }
}
