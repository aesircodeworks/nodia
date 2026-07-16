<?php

use App\Identity\OAuth\IdentityRefreshTokenRepository;
use App\Identity\OAuth\RefreshTokenRotationContext;
use Illuminate\Support\Str;
use Laravel\Passport\RefreshToken as PassportRefreshToken;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\RefreshTokenTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * The rotation family_id is carried on a request-scoped
 * App\Identity\OAuth\RefreshTokenRotationContext rather than a property on
 * the repository, because Passport's singleton AuthorizationServer
 * captures the repository and, under Octane, outlives the request. These
 * tests pin the two halves of that contract: revokeRefreshToken writes the
 * revoked token's family into the scoped context, and
 * persistNewRefreshToken consumes and clears it (so a failed issuance
 * cannot leave a stale family behind) while a brand-new login with an
 * empty context mints a fresh family.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    PassportRefreshToken::query()->delete();
    app(RefreshTokenRotationContext::class)->familyId = null;
});

function rotationRefreshTokenEntity(): RefreshTokenEntityInterface
{
    $accessToken = new class implements AccessTokenEntityInterface
    {
        use AccessTokenTrait, EntityTrait, TokenEntityTrait;
    };
    $accessToken->setIdentifier(Str::random(40));

    $refreshToken = new class implements RefreshTokenEntityInterface
    {
        use EntityTrait, RefreshTokenTrait;
    };
    $refreshToken->setIdentifier(Str::random(40));
    $refreshToken->setExpiryDateTime(new DateTimeImmutable('+1 day'));
    $refreshToken->setAccessToken($accessToken);

    return $refreshToken;
}

it('records the revoked token family on the scoped rotation context', function (): void {
    $familyId = (string) Str::uuid();

    $member = new PassportRefreshToken;
    $member->forceFill([
        'id' => Str::random(40),
        'access_token_id' => Str::random(40),
        'family_id' => $familyId,
        'revoked' => false,
        'expires_at' => now()->addDay(),
    ])->save();

    app(IdentityRefreshTokenRepository::class)->revokeRefreshToken($member->id);

    expect(app(RefreshTokenRotationContext::class)->familyId)->toBe($familyId);
});

it('carries the rotation family into the new token and clears the context', function (): void {
    $familyId = (string) Str::uuid();
    app(RefreshTokenRotationContext::class)->familyId = $familyId;

    $entity = rotationRefreshTokenEntity();
    app(IdentityRefreshTokenRepository::class)->persistNewRefreshToken($entity);

    expect(PassportRefreshToken::query()->whereKey($entity->getIdentifier())->value('family_id'))->toBe($familyId)
        ->and(app(RefreshTokenRotationContext::class)->familyId)->toBeNull();
});

it('mints a fresh family for a brand-new login when the context is empty', function (): void {
    expect(app(RefreshTokenRotationContext::class)->familyId)->toBeNull();

    $entity = rotationRefreshTokenEntity();
    app(IdentityRefreshTokenRepository::class)->persistNewRefreshToken($entity);

    $familyId = PassportRefreshToken::query()->whereKey($entity->getIdentifier())->value('family_id');

    expect($familyId)->not->toBeNull()
        ->and(Str::isUuid($familyId))->toBeTrue()
        ->and(app(RefreshTokenRotationContext::class)->familyId)->toBeNull();
});
