<?php

use App\Identity\Support\InvitationTokenHasher;

/*
 * Stage-03 plan, task breakdown item 9: the hashing half of the invitation
 * acceptance token consumption guard, deterministic like
 * App\Identity\Support\PasswordResetTokenHasher and for the same reason:
 * the guard's own conditional UPDATE
 * (App\Identity\Actions\ConsumeInvitationToken) looks a presented token up
 * by an equality match on token_hash, which a random-salt hash could never
 * satisfy twice for the same input.
 */

it('hashes the same plaintext token to the same value every time', function () {
    $token = 'a-plaintext-invitation-token';

    expect(InvitationTokenHasher::hash($token))->toBe(InvitationTokenHasher::hash($token));
});

it('hashes different plaintext tokens to different values', function () {
    expect(InvitationTokenHasher::hash('a-plaintext-invitation-token'))
        ->not->toBe(InvitationTokenHasher::hash('a-different-invitation-token'));
});

it('never stores the plaintext token as its own hash', function () {
    $token = 'a-plaintext-invitation-token';

    expect(InvitationTokenHasher::hash($token))->not->toBe($token);
});
