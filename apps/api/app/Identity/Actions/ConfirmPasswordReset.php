<?php

namespace App\Identity\Actions;

use App\Identity\Data\ConfirmPasswordResetData;
use App\Identity\Exceptions\PasswordResetTokenExpiredException;
use App\Identity\Exceptions\PasswordResetTokenInvalidException;
use App\Identity\Models\StaffPasswordResetToken;
use App\Identity\Support\PasswordResetTokenHasher;
use App\Models\User;

/**
 * POST /v1/auth/staff/password/reset/confirm (stage-03 plan, task
 * breakdown item 16). Redeems the single-use token
 * POST /v1/auth/staff/password/reset mails, sets the new password, and
 * revokes every live access and refresh token the user held (Risks:
 * closing the gap invitation acceptance leaves for a forgotten, rather
 * than first, credential). The affected-row-count guard
 * (App\Identity\Actions\ConsumePasswordResetToken) decides success before
 * any other lookup runs; only on its failure does this class look the row
 * up again, purely to distinguish reset_token_expired (a live row whose
 * expiry passed) from reset_token_invalid (unknown, tampered, or already
 * consumed), mirroring
 * App\Identity\OAuth\IdentityRefreshTokenRepository::revokeRefreshToken's
 * own precedent for a read that never participates in the guard's own
 * decision.
 */
final class ConfirmPasswordReset
{
    public function __construct(
        private readonly ConsumePasswordResetToken $consume,
        private readonly RevokeAllUserTokens $revokeTokens,
    ) {}

    public function __invoke(ConfirmPasswordResetData $data): void
    {
        $tokenHash = PasswordResetTokenHasher::hash($data->token);

        if (! ($this->consume)($tokenHash)) {
            $existing = StaffPasswordResetToken::query()->where('token_hash', $tokenHash)->first();

            if ($existing !== null && $existing->consumed_at === null) {
                throw PasswordResetTokenExpiredException::make();
            }

            throw PasswordResetTokenInvalidException::make();
        }

        $resetToken = StaffPasswordResetToken::query()->where('token_hash', $tokenHash)->firstOrFail();

        $user = User::query()->find($resetToken->user_id);

        if ($user === null) {
            // The token's own hash already matched a live, just-consumed
            // row; a missing user only happens if the account was deleted
            // after the token was issued, which Stage 3 has no path for
            // yet. Rendered identically to a tampered token so the
            // response never distinguishes the two.
            throw PasswordResetTokenInvalidException::make();
        }

        $user->update(['password' => $data->password]);

        ($this->revokeTokens)($user->id);
    }
}
