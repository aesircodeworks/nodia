<?php

namespace App\Identity\Support;

/**
 * Hashes a password reset token's plaintext for storage and lookup
 * (stage-03 plan, task breakdown item 16). Deliberately unsalted and
 * deterministic, mirroring App\Identity\Support\RecoveryCodeHasher exactly
 * and for the same reason: the consumption guard
 * (App\Identity\Actions\ConsumePasswordResetToken) looks a presented token
 * up by an equality match on password_reset_tokens.token_hash, which a
 * random-salt hash (bcrypt, argon2, anything Hash::make() produces) could
 * never satisfy twice for the same input. sha256 over the plaintext is
 * sufficient here because a reset token is a single-use, ephemeral,
 * high-entropy random string, never a user-chosen, low-entropy password:
 * it needs collision resistance and a stable digest, not brute-force-
 * resistant slow hashing.
 */
final class PasswordResetTokenHasher
{
    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
