<?php

use App\Identity\Actions\RevokeRefreshTokenFamily;
use App\Identity\OAuth\IdentityRefreshTokenRepository;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\RefreshToken as PassportRefreshToken;
use Laravel\Passport\Token as PassportToken;
use League\OAuth2\Server\Exception\OAuthServerException;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    PassportRefreshToken::query()->delete();
    PassportToken::query()->delete();
});

function familyTestClient(): Client
{
    return Client::query()->where('provider', 'users')->firstOrFail();
}

function familyTestAccessToken(bool $revoked = false): PassportToken
{
    $token = new PassportToken;

    $token->forceFill([
        'id' => Str::random(40),
        'client_id' => familyTestClient()->getKey(),
        'revoked' => $revoked,
    ])->save();

    return $token;
}

function familyTestRefreshToken(string $accessTokenId, string $familyId, bool $revoked = false): PassportRefreshToken
{
    $token = new PassportRefreshToken;

    $token->forceFill([
        'id' => Str::random(40),
        'access_token_id' => $accessTokenId,
        'family_id' => $familyId,
        'revoked' => $revoked,
        'expires_at' => now()->addDay(),
    ])->save();

    return $token;
}

describe('RevokeRefreshTokenFamily', function (): void {
    it('revokes every live refresh token in a family and the access token issued alongside each one', function (): void {
        $familyId = (string) Str::uuid();
        $otherFamilyId = (string) Str::uuid();

        $liveAccessToken = familyTestAccessToken();
        $alreadyRevokedAccessToken = familyTestAccessToken(revoked: true);
        $otherFamilyAccessToken = familyTestAccessToken();

        $liveMember = familyTestRefreshToken($liveAccessToken->id, $familyId);
        $alreadyRevokedMember = familyTestRefreshToken($alreadyRevokedAccessToken->id, $familyId, revoked: true);
        $otherFamilyMember = familyTestRefreshToken($otherFamilyAccessToken->id, $otherFamilyId);

        app(RevokeRefreshTokenFamily::class)($familyId);

        expect($liveMember->refresh()->revoked)->toBeTrue()
            ->and($liveAccessToken->refresh()->revoked)->toBeTrue()
            ->and($alreadyRevokedMember->refresh()->revoked)->toBeTrue()
            ->and($otherFamilyMember->refresh()->revoked)->toBeFalse()
            ->and($otherFamilyAccessToken->refresh()->revoked)->toBeFalse();
    });

    it('is a no-op for a family with no live members', function (): void {
        $familyId = (string) Str::uuid();
        $accessToken = familyTestAccessToken(revoked: true);
        $member = familyTestRefreshToken($accessToken->id, $familyId, revoked: true);

        app(RevokeRefreshTokenFamily::class)($familyId);

        expect($member->refresh()->revoked)->toBeTrue()
            ->and($accessToken->refresh()->revoked)->toBeTrue();
    });
});

describe('IdentityRefreshTokenRepository::isRefreshTokenRevoked', function (): void {
    it('reports a live refresh token as not revoked and leaves its family untouched', function (): void {
        $familyId = (string) Str::uuid();
        $accessToken = familyTestAccessToken();
        $member = familyTestRefreshToken($accessToken->id, $familyId);

        expect(app(IdentityRefreshTokenRepository::class)->isRefreshTokenRevoked($member->id))->toBeFalse()
            ->and($member->refresh()->revoked)->toBeFalse();
    });

    it('reports an already-revoked refresh token as revoked and revokes every other live token in its family', function (): void {
        $familyId = (string) Str::uuid();

        $revokedAccessToken = familyTestAccessToken();
        $revokedMember = familyTestRefreshToken($revokedAccessToken->id, $familyId, revoked: true);

        $liveAccessToken = familyTestAccessToken();
        $liveMember = familyTestRefreshToken($liveAccessToken->id, $familyId);

        expect(app(IdentityRefreshTokenRepository::class)->isRefreshTokenRevoked($revokedMember->id))->toBeTrue()
            ->and($liveMember->refresh()->revoked)->toBeTrue()
            ->and($liveAccessToken->refresh()->revoked)->toBeTrue();
    });

    it('reports an unknown refresh token id as revoked without touching any row', function (): void {
        $familyId = (string) Str::uuid();
        $accessToken = familyTestAccessToken();
        $member = familyTestRefreshToken($accessToken->id, $familyId);

        expect(app(IdentityRefreshTokenRepository::class)->isRefreshTokenRevoked(Str::random(40)))->toBeTrue()
            ->and($member->refresh()->revoked)->toBeFalse();
    });
});

describe('IdentityRefreshTokenRepository::revokeRefreshToken', function (): void {
    it('conditionally revokes a live token once and rejects a second attempt without revoking its family', function (): void {
        $familyId = (string) Str::uuid();
        $accessToken = familyTestAccessToken();
        $member = familyTestRefreshToken($accessToken->id, $familyId);

        $sibling = familyTestRefreshToken(familyTestAccessToken()->id, $familyId);

        $repository = app(IdentityRefreshTokenRepository::class);

        $repository->revokeRefreshToken($member->id);

        expect($member->refresh()->revoked)->toBeTrue();

        expect(fn () => $repository->revokeRefreshToken($member->id))
            ->toThrow(function (OAuthServerException $e): void {
                expect($e->getErrorType())->toBe('invalid_grant')
                    ->and($e->getHint())->toBe(IdentityRefreshTokenRepository::RaceLostHint);
            });

        // The rotation-race loss must not cascade into family revocation:
        // a legitimate sibling token is unaffected.
        expect($sibling->refresh()->revoked)->toBeFalse();
    });
});
