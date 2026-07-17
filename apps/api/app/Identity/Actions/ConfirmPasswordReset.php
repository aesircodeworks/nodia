<?php

namespace App\Identity\Actions;

use App\Identity\Data\ConfirmPasswordResetData;
use App\Identity\Exceptions\PasswordResetTokenExpiredException;
use App\Identity\Exceptions\PasswordResetTokenInvalidException;
use App\Identity\Models\StaffPasswordResetToken;
use App\Identity\Support\PasswordResetTokenHasher;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * POST /v1/auth/staff/password/reset/confirm (stage-03 plan, task
 * breakdown item 16). Redeems the single-use token
 * POST /v1/auth/staff/password/reset mails, sets the new password, and
 * revokes every live access and refresh token the user held. The user row
 * is locked before token consumption and held through credential update
 * and revocation; staff password and refresh grants take the same lock,
 * so no new pair can escape the all-sessions reset. A successful reset
 * consumes every sibling reset token in that same transaction.
 */
final class ConfirmPasswordReset
{
    public function __construct(
        private readonly ConsumePasswordResetToken $consume,
        private readonly RevokeAllUserTokens $revokeTokens,
    ) {}

    public function __invoke(ConfirmPasswordResetData $data): void
    {
        DB::transaction(function () use ($data): void {
            $tokenHash = PasswordResetTokenHasher::hash($data->token);
            $resetToken = StaffPasswordResetToken::query()->where('token_hash', $tokenHash)->first();

            if ($resetToken === null) {
                throw PasswordResetTokenInvalidException::make();
            }

            $user = User::query()->whereKey($resetToken->user_id)->lockForUpdate()->first();

            if ($user === null) {
                throw PasswordResetTokenInvalidException::make();
            }

            if (! ($this->consume)($tokenHash)) {
                $resetToken->refresh();

                if ($resetToken->consumed_at === null) {
                    throw PasswordResetTokenExpiredException::make();
                }

                throw PasswordResetTokenInvalidException::make();
            }

            $user->update(['password' => $data->password]);

            $now = Date::now();
            StaffPasswordResetToken::query()
                ->where('user_id', $user->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => $now, 'updated_at' => $now]);

            ($this->revokeTokens)($user->id);
        });
    }
}
