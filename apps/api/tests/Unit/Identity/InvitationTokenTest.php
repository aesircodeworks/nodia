<?php

use App\Identity\Exceptions\InvitationTokenExpiredException;
use App\Identity\Exceptions\InvitationTokenInvalidException;
use App\Identity\Support\InvitationToken;
use Illuminate\Support\Str;

/*
 * Stage-03 plan, task breakdown item 9: "Unit tests for the Actions" plus
 * the plan's own TDD sequencing note ("invitation acceptance including
 * ... tampered tokens under the fake clock"). Exercises the token codec
 * directly, without touching the database or HTTP layer (the feature
 * suite, tests/Feature/Identity/InvitationAcceptanceTest.php, proves the
 * endpoint end to end).
 */

it('round-trips the invited user id', function () {
    $userId = (string) Str::uuid7();

    $token = InvitationToken::issue($userId);

    expect(InvitationToken::verify($token))->toBe($userId);
});

it('issues a token that has not expired the instant it is issued', function () {
    $userId = (string) Str::uuid7();

    $token = InvitationToken::issue($userId);

    expect(InvitationToken::verify($token))->toBe($userId);
});

it('accepts a token one second before its configured ttl elapses', function () {
    test()->freezeTime();

    $userId = (string) Str::uuid7();
    $token = InvitationToken::issue($userId);

    test()->travel(config()->integer('identity.invitation_token_ttl_minutes') * 60 - 1)->seconds();

    expect(InvitationToken::verify($token))->toBe($userId);
});

it('treats the exact expiry instant as expired, not one second later', function () {
    test()->freezeTime();

    $token = InvitationToken::issue((string) Str::uuid7());

    test()->travel(config()->integer('identity.invitation_token_ttl_minutes') * 60)->seconds();

    expect(fn () => InvitationToken::verify($token))->toThrow(InvitationTokenExpiredException::class);
});

it('rejects a tampered token as invalid, not expired', function () {
    $token = InvitationToken::issue((string) Str::uuid7());
    $tampered = substr($token, 0, -1).($token[-1] === 'a' ? 'b' : 'a');

    expect(fn () => InvitationToken::verify($tampered))->toThrow(InvitationTokenInvalidException::class);
});

it('rejects a malformed, non-encrypted string as invalid', function () {
    expect(fn () => InvitationToken::verify('not-a-real-token'))->toThrow(InvitationTokenInvalidException::class);
});
