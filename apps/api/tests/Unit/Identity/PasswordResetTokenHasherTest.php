<?php

use App\Identity\Support\PasswordResetTokenHasher;

/*
 * Stage-03 plan, Slice 8 (task breakdown item 16): the hashing half of the
 * password reset token consumption guard, deterministic like
 * App\Identity\Support\RecoveryCodeHasher and for the same reason: the
 * guard's own conditional UPDATE (App\Identity\Actions\ConsumePasswordResetToken)
 * looks a presented token up by an equality match on token_hash, which a
 * random-salt hash could never satisfy twice for the same input.
 */

it('hashes the same plaintext token to the same value every time', function () {
    $token = 'a-plaintext-reset-token';

    expect(PasswordResetTokenHasher::hash($token))->toBe(PasswordResetTokenHasher::hash($token));
});

it('hashes different plaintext tokens to different values', function () {
    expect(PasswordResetTokenHasher::hash('a-plaintext-reset-token'))
        ->not->toBe(PasswordResetTokenHasher::hash('a-different-reset-token'));
});

it('never stores the plaintext token as its own hash', function () {
    $token = 'a-plaintext-reset-token';

    expect(PasswordResetTokenHasher::hash($token))->not->toBe($token);
});
