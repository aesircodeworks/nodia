<?php

namespace App\Identity\Actions;

use App\Identity\Data\MfaEnrollmentData;
use App\Identity\Exceptions\MfaAlreadyEnrolledException;
use App\Identity\Support\TotpVerifier;
use App\Models\User;

/**
 * POST /v1/auth/mfa/enrollment (stage-03 plan, MFA endpoint table).
 * Generates a fresh TOTP secret and persists it unconfirmed
 * (mfa_enabled stays false, mfa_confirmed_at stays null) until
 * ConfirmMfaEnrollment verifies a code against it. Calling this again
 * before confirming simply replaces the pending secret, a deliberate
 * re-enrollment path with no dedicated error code (only an
 * already-confirmed account is rejected).
 */
final class EnrollMfa
{
    public function __construct(private readonly TotpVerifier $totp) {}

    public function __invoke(User $user): MfaEnrollmentData
    {
        if ($user->mfa_enabled) {
            throw MfaAlreadyEnrolledException::make();
        }

        $secret = $this->totp->generateSecret();

        $user->forceFill(['mfa_secret' => $secret])->save();

        return new MfaEnrollmentData($secret, $this->totp->otpauthUri($secret, $user->email));
    }
}
