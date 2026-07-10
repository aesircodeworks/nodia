<?php

namespace App\Identity\Actions;

use App\Identity\Data\CustomerTokenRequestData;
use App\Identity\Data\TokenPairData;
use App\Identity\Exceptions\InvalidCredentialsException;
use Illuminate\Http\Request;
use Laravel\Passport\Client;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

/**
 * Mirrors App\Identity\Actions\IssueStaffToken's approach of driving
 * Passport's AuthorizationServer directly with grant_type=password
 * (stage-03 task-01/02 journals), simpler here: customers carry no MFA
 * challenge, so unlike IssueStaffToken this needs no manual pre-check of
 * credentials before the grant runs. Laravel\Passport\Bridge\UserRepository's
 * own Hash::check against Customer::getAuthPassword() already returns
 * false for a guest's null password column
 * (Illuminate\Hashing\AbstractHasher::check(): a null or empty hashed
 * value short-circuits to false rather than throwing, confirmed against
 * vendor source), which league/oauth2-server's PasswordGrant maps to the
 * same invalid_grant this class maps to invalid_credentials, satisfying
 * "token issuance for an unclaimed guest fails with invalid_credentials"
 * with no extra code path.
 */
final class IssueCustomerToken
{
    private const string CustomerProvider = 'customers';

    public function __construct(
        private readonly AuthorizationServer $server,
        private readonly ResponseInterface $blankResponse,
    ) {}

    public function __invoke(CustomerTokenRequestData $data): TokenPairData
    {
        $request = Request::create('/v1/auth/customer/token', 'POST', [
            'grant_type' => 'password',
            'client_id' => $this->customerClient()->getKey(),
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

    private function customerClient(): Client
    {
        // Exactly one password-grant client is seeded per provider (task 1
        // of this stage); `password_client` is a virtual Passport
        // attribute derived from the grant_types column, not a real
        // column, so it cannot be queried at the database level.
        return Client::query()
            ->where('provider', self::CustomerProvider)
            ->firstOrFail();
    }
}
