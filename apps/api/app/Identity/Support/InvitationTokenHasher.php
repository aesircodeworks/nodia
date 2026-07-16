<?php

namespace App\Identity\Support;

/**
 * Hashes an invitation acceptance token's plaintext for storage and
 * lookup (stage-03 plan, task breakdown item 9). Deliberately unsalted and
 * deterministic, mirroring App\Identity\Support\PasswordResetTokenHasher
 * exactly and for the same reason: the consumption guard
 * (App\Identity\Actions\ConsumeInvitationToken) looks a presented token up
 * by an equality match on staff_invitation_tokens.token_hash, which a
 * random-salt hash (bcrypt, argon2, anything Hash::make() produces) could
 * never satisfy twice for the same input. sha256 over the plaintext is
 * sufficient here because an invitation token is a single-use, ephemeral,
 * high-entropy random string, never a user-chosen, low-entropy password:
 * it needs collision resistance and a stable digest, not
 * brute-force-resistant slow hashing.
 */
final class InvitationTokenHasher
{
    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
