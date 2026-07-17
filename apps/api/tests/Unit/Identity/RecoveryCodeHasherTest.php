<?php

use App\Identity\Support\RecoveryCodeHasher;

/*
 * Task breakdown item 10 (stage-03 plan): the hashing half of the
 * recovery-code consumption guard, written and failing before the guard
 * exists. App\Identity\Support\RecoveryCodeHasher does not exist yet;
 * task breakdown item 11 (Slice 5) builds it alongside
 * App\Identity\Actions\ConsumeRecoveryCode (RecoveryCodeConsumptionTest.php,
 * tests/Concurrency/RecoveryCodeConsumptionContentionTest.php). Hashing
 * must be deterministic, not salted the way password hashing is: the
 * guard's own conditional UPDATE (Data model "mfa_recovery_codes") looks a
 * presented code up by an equality match on code_hash, which a
 * random-salt hash could never satisfy twice for the same input.
 */

it('hashes the same plaintext code to the same value every time', function () {
    $code = 'ABCD-1234-EFGH';

    expect(RecoveryCodeHasher::hash($code))->toBe(RecoveryCodeHasher::hash($code));
});

it('hashes different plaintext codes to different values', function () {
    expect(RecoveryCodeHasher::hash('ABCD-1234-EFGH'))
        ->not->toBe(RecoveryCodeHasher::hash('WXYZ-5678-IJKL'));
});

it('never stores the plaintext code as its own hash', function () {
    $code = 'ABCD-1234-EFGH';

    expect(RecoveryCodeHasher::hash($code))->not->toBe($code);
});
