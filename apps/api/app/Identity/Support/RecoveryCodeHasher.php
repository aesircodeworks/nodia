<?php

namespace App\Identity\Support;

/**
 * Hashes an MFA recovery code's plaintext for storage and lookup
 * (stage-03 plan, task breakdown item 10's design pin, task breakdown
 * item 11). Deliberately unsalted and deterministic: the consumption
 * guard (App\Identity\Actions\ConsumeRecoveryCode) looks a presented code
 * up by an equality match on mfa_recovery_codes.code_hash, which a
 * random-salt hash (bcrypt, argon2, anything Hash::make() produces) could
 * never satisfy twice for the same input. sha256 over the plaintext is
 * sufficient here because a recovery code is a single-use, ephemeral,
 * high-entropy random token (RecoveryCodeGenerator), never a
 * user-chosen, low-entropy password: it needs collision resistance and a
 * stable digest, not brute-force-resistant slow hashing.
 */
final class RecoveryCodeHasher
{
    public static function hash(string $plainCode): string
    {
        return hash('sha256', $plainCode);
    }
}
