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
 * Drives Passport's AuthorizationServer directly with
 * grant_type=refresh_token, mirroring IssueStaffToken's approach to the
 * password grant (stage-03 task-01 and task-02 journals): the wire
 * contract stays {refresh_token} rather than the raw OAuth request shape.
 * All of the rotation, reuse-detection, and family-revocation behavior
 * lives in App\Identity\OAuth\IdentityRefreshTokenRepository; this class
 * only translates between the wire contract and league/oauth2-server's
 * grant, and maps its two possible invalid_grant failures onto the
 * stage's two distinct error codes by the exception hint league attaches.
 */
final class RotateStaffToken
{
    private const string StaffProvider = 'users';

    public function __construct(
        private readonly AuthorizationServer $server,
        private readonly ResponseInterface $blankResponse,
    ) {}

    public function __invoke(RefreshTokenRequestData $data): TokenPairData
    {
        $request = Request::create('/v1/auth/staff/refresh', 'POST', [
            'grant_type' => 'refresh_token',
            'client_id' => $this->staffClient()->getKey(),
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

    private function staffClient(): Client
    {
        return Client::query()
            ->where('provider', self::StaffProvider)
            ->firstOrFail();
    }
}
