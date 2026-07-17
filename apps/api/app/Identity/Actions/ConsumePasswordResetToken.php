<?php

namespace App\Identity\Actions;

use App\Identity\Models\StaffPasswordResetToken;
use Illuminate\Support\Facades\Date;

/**
 * Atomically consumes a password reset token by its digest (stage-03
 * plan, task breakdown item 16). A
 * conditional UPDATE checked by affected-row count, never a read-then-
 * write existence check first (master plan test-first rule 2; CLAUDE.md),
 * mirroring App\Identity\Actions\ConsumeRecoveryCode's own precedent for
 * the sibling single-use-token guard: two parallel presentations of the
 * same token can only ever see the row transition from unconsumed to
 * consumed once, proven by
 * tests/Concurrency/PasswordResetTokenConsumptionContentionTest.php.
 *
 * Deliberately silent on why a token fails to consume: App\Identity\Actions\ConfirmPasswordReset
 * is the only caller, and it decides reset_token_invalid versus
 * reset_token_expired with its own follow-up lookup, run only after this
 * guard has already decided the outcome, mirroring
 * App\Identity\OAuth\IdentityRefreshTokenRepository::revokeRefreshToken's
 * own family_id lookup precedent for the same reason.
 */
final class ConsumePasswordResetToken
{
    public function __invoke(string $tokenHash): bool
    {
        $affected = StaffPasswordResetToken::query()
            ->where('token_hash', $tokenHash)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', Date::now())
            ->update(['consumed_at' => Date::now()]);

        return $affected > 0;
    }
}
