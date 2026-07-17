<?php

namespace App\Identity\Actions;

use App\Identity\Data\StaffTokenRequestData;
use App\Identity\Data\TokenPairData;
use App\Identity\Exceptions\InvalidCredentialsException;
use App\Models\User;
use App\Support\Audit\ActivityLogger;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
 *
 * The MFA challenge (stage-03 plan, Risks: "MFA challenge inside the
 * OAuth token exchange") is the mfa_code parameter injected straight into
 * this same token request, the plan's preferred approach over the
 * two-step mfa_token fallback: parameter injection proved entirely
 * workable once this class already owned building the Request passed to
 * the AuthorizationServer, so the fallback was never needed. Credentials
 * are verified once, manually, with Hash::check against the same bcrypt
 * digest Passport's own UserRepository checks, before either the MFA
 * challenge or the AuthorizationServer call: this way a wrong mfa_code
 * never mints and then discards a real access/refresh token pair, and an
 * unknown email or wrong password still renders identically
 * (invalid_credentials) whether or not the account has MFA enabled, so
 * MFA enrollment itself is never revealed to a caller who fails on
 * credentials alone. The subsequent AuthorizationServer call re-checks
 * the same password through Passport's own grant, which is redundant but
 * harmless and keeps the actual token-minting path untouched.
 *
 * A successful token issuance also records an activity_log entry under
 * the sentinel platform tenant (stage-03 plan, Slice 7: "successful staff
 * token issuance writes a log entry"), the one other login-adjacent audit
 * requirement task breakdown item 15 names besides the mutating-endpoint
 * and platform-role-use coverage: staff login has no acting tenant of its
 * own (this endpoint is mounted with no X-Tenant-Id and no tenant
 * transaction, unlike every other audited write in this stage), so the
 * entry is written inside a short, dedicated platform transaction opened
 * just for the log call, mirroring PlatformRoleAudit's own precedent for
 * a request with no ambient tenant context. Only a fully successful
 * exchange reaches this call: a wrong password, unknown email, or failed
 * MFA challenge throws before either the AuthorizationServer or the audit
 * write ever runs.
 */
final class IssueStaffToken
{
    private const string StaffProvider = 'users';

    public function __construct(
        private readonly AuthorizationServer $server,
        private readonly ResponseInterface $blankResponse,
        private readonly VerifyMfaChallenge $mfaChallenge,
        private readonly TenantTransaction $transaction,
        private readonly ActivityLogger $activityLog,
    ) {}

    public function __invoke(StaffTokenRequestData $data): TokenPairData
    {
        return DB::transaction(fn (): TokenPairData => $this->issue($data));
    }

    private function issue(StaffTokenRequestData $data): TokenPairData
    {
        $user = $this->authenticate($data->email, $data->password);

        ($this->mfaChallenge)($user, $data->mfaCode);

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

        $this->transaction->asPlatform(fn () => $this->activityLog->record(
            description: sprintf('staff login: %s', $user->email),
            causer: $user,
            event: 'staff_login',
        ));

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

    private function authenticate(string $email, string $password): User
    {
        $user = User::query()->where('email', $email)->lockForUpdate()->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            throw InvalidCredentialsException::becauseAuthenticationFailed();
        }

        return $user;
    }
}
