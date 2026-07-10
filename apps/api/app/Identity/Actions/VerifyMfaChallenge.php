<?php

namespace App\Identity\Actions;

use App\Identity\Exceptions\MfaCodeInvalidException;
use App\Identity\Exceptions\MfaRequiredException;
use App\Identity\Support\TotpVerifier;
use App\Models\User;

/**
 * The staff token exchange's MFA challenge (stage-03 plan, Staff
 * authentication endpoint table: "mfa_required is returned when the user
 * has confirmed MFA and no mfa_code was supplied; a recovery code is
 * accepted in place of a TOTP code and consumed atomically"). A no-op for
 * a user who has not confirmed MFA, so IssueStaffToken can call this
 * unconditionally for every credential-valid request.
 */
final class VerifyMfaChallenge
{
    public function __construct(
        private readonly TotpVerifier $totp,
        private readonly ConsumeRecoveryCode $consumeRecoveryCode,
    ) {}

    public function __invoke(User $user, ?string $code): void
    {
        if (! $user->mfa_enabled) {
            return;
        }

        if ($code === null) {
            throw MfaRequiredException::make();
        }

        $validTotp = $this->totp->verify((string) $user->mfa_secret, $code);

        if (! $validTotp && ! ($this->consumeRecoveryCode)($user->id, $code)) {
            throw MfaCodeInvalidException::make();
        }
    }
}
