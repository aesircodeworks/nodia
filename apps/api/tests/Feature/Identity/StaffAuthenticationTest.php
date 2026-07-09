<?php

use App\Models\User;
use Illuminate\Support\Arr;
use Laravel\Passport\Passport;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    User::query()->delete();
});

function staffUser(array $attributes = []): User
{
    return User::factory()->create($attributes);
}

function decodeAccessToken(string $jwt): Plain
{
    /** @var Plain $token */
    $token = (new Parser(new JoseEncoder))->parse($jwt);

    return $token;
}

/**
 * Rebuilds the real access token with the same jti (so it still resolves
 * as a live, non-revoked row in oauth_access_tokens), sub, and aud, but
 * with iat/nbf/exp moved into the past. league/oauth2-server and
 * lcobucci/jwt build and validate the exp claim with raw
 * `new DateTimeImmutable('now')` (confirmed against vendor source in the
 * stage-03 task-02 journal), never through the app's controllable clock,
 * so freezeTime()/travel() cannot move the real token's expiry boundary.
 * This exercises the exact validation branch (BearerTokenValidator's
 * LooseValidAt constraint) that will reject the real token 901 seconds
 * after issuance, deterministically and without waiting for real time to
 * pass.
 */
function expiredCopyOf(string $accessToken): string
{
    $claims = decodeAccessToken($accessToken)->claims();

    $config = Configuration::forAsymmetricSigner(
        new Sha256,
        InMemory::plainText((string) file_get_contents(Passport::keyPath('oauth-private.key'))),
        InMemory::plainText('empty', 'empty'),
    );

    $past = now()->subMinutes(20)->toDateTimeImmutable();

    $token = $config->builder()
        ->permittedFor(...$claims->get('aud'))
        ->identifiedBy($claims->get('jti'))
        ->issuedAt($past)
        ->canOnlyBeUsedAfter($past)
        ->expiresAt($past)
        ->relatedTo($claims->get('sub'))
        ->withClaim('scopes', $claims->get('scopes'))
        ->withClaim('identity_type', $claims->get('identity_type'))
        ->getToken($config->signer(), $config->signingKey());

    return $token->toString();
}

describe('POST /v1/auth/staff/token', function (): void {
    it('issues a token pair for valid credentials', function (): void {
        staffUser(['email' => 'staff@example.com']);

        $response = $this->postJson('/v1/auth/staff/token', [
            'email' => 'staff@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertConformsToOpenApi()
            ->assertJson([
                'token_type' => 'Bearer',
                'expires_in' => config()->integer('identity.access_token_ttl_minutes') * 60,
            ]);

        expect(array_keys($response->json()))
            ->toBe(['access_token', 'refresh_token', 'token_type', 'expires_in'])
            ->and($response->json('access_token'))->toBeString()
            ->and($response->json('refresh_token'))->toBeString();
    });

    it('rejects an unknown email the same way as a wrong password, so neither reveals whether the account exists', function (): void {
        staffUser(['email' => 'staff@example.com']);

        $unknownEmail = $this->postJson('/v1/auth/staff/token', [
            'email' => 'ghost@example.com',
            'password' => 'password',
        ]);

        $wrongPassword = $this->postJson('/v1/auth/staff/token', [
            'email' => 'staff@example.com',
            'password' => 'not-the-password',
        ]);

        foreach ([$unknownEmail, $wrongPassword] as $response) {
            $response->assertStatus(401)
                ->assertConformsToOpenApi()
                ->assertJson(['code' => 'invalid_credentials']);
        }

        expect(Arr::except($unknownEmail->json(), 'correlation_id'))
            ->toBe(Arr::except($wrongPassword->json(), 'correlation_id'));
    });

    it('fails request validation for a malformed payload', function (): void {
        $this->postJson('/v1/auth/staff/token', ['email' => 'not-an-email', 'password' => ''])
            ->assertStatus(422)
            ->assertConformsToOpenApi()
            ->assertJson(['code' => 'request.validation_failed']);
    });

    it('carries identity_type staff and no tenant claim', function (): void {
        staffUser(['email' => 'staff@example.com']);

        $response = $this->postJson('/v1/auth/staff/token', [
            'email' => 'staff@example.com',
            'password' => 'password',
        ]);

        $claims = decodeAccessToken($response->json('access_token'))->claims();

        expect($claims->get('identity_type'))->toBe('staff')
            ->and($claims->has('tenant_id'))->toBeFalse();
    });

    it('rejects the access token once it is past its expiry', function (): void {
        staffUser(['email' => 'staff@example.com']);

        $issued = $this->postJson('/v1/auth/staff/token', [
            'email' => 'staff@example.com',
            'password' => 'password',
        ])->json('access_token');

        $expired = expiredCopyOf($issued);

        $this->getJson('/v1/me', ['Authorization' => 'Bearer '.$expired])
            ->assertStatus(401)
            ->assertJson(['code' => 'auth.unauthenticated']);
    });
});

describe('GET /v1/me', function (): void {
    it('returns the authenticated staff user with an empty memberships list', function (): void {
        $user = staffUser(['email' => 'staff@example.com', 'name' => 'Staffer']);

        $token = $this->postJson('/v1/auth/staff/token', [
            'email' => 'staff@example.com',
            'password' => 'password',
        ])->json('access_token');

        $this->getJson('/v1/me', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertExactJson([
                'id' => $user->id,
                'name' => 'Staffer',
                'email' => 'staff@example.com',
                'mfa_enabled' => false,
                'memberships' => [],
            ]);
    });

    it('is unauthenticated without a bearer token', function (): void {
        $this->getJson('/v1/me')
            ->assertStatus(401)
            ->assertConformsToOpenApi()
            ->assertJson(['code' => 'auth.unauthenticated']);
    });

    it('is unauthenticated with a garbage bearer token', function (): void {
        $this->getJson('/v1/me', ['Authorization' => 'Bearer garbage'])
            ->assertStatus(401)
            ->assertJson(['code' => 'auth.unauthenticated']);
    });
});
