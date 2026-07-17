<?php

use App\Identity\Exceptions\ClaimTokenExpiredException;
use App\Identity\Exceptions\ClaimTokenInvalidException;
use App\Identity\Support\ClaimToken;
use Illuminate\Support\Str;

/*
 * Stage-03 plan, task breakdown item 13. Mirrors
 * tests/Unit/Identity/InvitationTokenTest.php's own precedent exactly:
 * exercises the token codec directly, without touching the database or
 * HTTP layer (the feature suite,
 * tests/Feature/Identity/CustomerClaimTest.php, proves the endpoint end
 * to end).
 */

it('round-trips the claimed customer id', function () {
    $customerId = (string) Str::uuid7();

    $token = ClaimToken::issue($customerId);

    expect(ClaimToken::verify($token))->toBe($customerId);
});

it('accepts a token one second before its configured ttl elapses', function () {
    test()->freezeTime();

    $customerId = (string) Str::uuid7();
    $token = ClaimToken::issue($customerId);

    test()->travel(config()->integer('identity.claim_token_ttl_minutes') * 60 - 1)->seconds();

    expect(ClaimToken::verify($token))->toBe($customerId);
});

it('treats the exact expiry instant as expired, not one second later', function () {
    test()->freezeTime();

    $token = ClaimToken::issue((string) Str::uuid7());

    test()->travel(config()->integer('identity.claim_token_ttl_minutes') * 60)->seconds();

    expect(fn () => ClaimToken::verify($token))->toThrow(ClaimTokenExpiredException::class);
});

it('rejects a tampered token as invalid, not expired', function () {
    $token = ClaimToken::issue((string) Str::uuid7());
    $tampered = substr($token, 0, -1).($token[-1] === 'a' ? 'b' : 'a');

    expect(fn () => ClaimToken::verify($tampered))->toThrow(ClaimTokenInvalidException::class);
});

it('rejects a malformed, non-encrypted string as invalid', function () {
    expect(fn () => ClaimToken::verify('not-a-real-token'))->toThrow(ClaimTokenInvalidException::class);
});
