<?php

namespace App\Identity\Actions;

use App\Identity\Data\DisableMfaData;
use App\Identity\Exceptions\MfaCodeInvalidException;
use App\Identity\Exceptions\MfaEnforcedForRoleException;
use App\Identity\Exceptions\MfaNotEnrolledException;
use App\Identity\Models\MfaRecoveryCode;
use App\Identity\Support\TotpVerifier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * POST /v1/auth/mfa/disable (stage-03 plan, MFA endpoint table).
 * Enforcement is checked before the code, so a caller whose membership
 * mandates MFA is denied without spending a recovery code on a request
 * that could never succeed. The presented code accepts either a valid
 * TOTP code or a still-unused recovery code, the same either/or the
 * staff token exchange accepts, so disabling MFA is provable the same
 * way logging in with it already is.
 */
final class DisableMfa
{
    public function __construct(
        private readonly TotpVerifier $totp,
        private readonly HasEnforcingMembership $enforcement,
        private readonly ConsumeRecoveryCode $consumeRecoveryCode,
    ) {}

    public function __invoke(User $user, DisableMfaData $data): void
    {
        if (! $user->mfa_enabled) {
            throw MfaNotEnrolledException::make();
        }

        if ($this->enforcement->forUser($user->id)) {
            throw MfaEnforcedForRoleException::make();
        }

        $validTotp = $this->totp->verify((string) $user->mfa_secret, $data->code);

        if (! $validTotp && ! ($this->consumeRecoveryCode)($user->id, $data->code)) {
            throw MfaCodeInvalidException::make();
        }

        DB::transaction(function () use ($user): void {
            $user->forceFill([
                'mfa_enabled' => false,
                'mfa_secret' => null,
                'mfa_confirmed_at' => null,
            ])->save();

            MfaRecoveryCode::query()->where('user_id', $user->id)->delete();
        });
    }
}
