<?php

namespace App\Identity\Actions;

use App\Identity\Data\ConfirmMfaData;
use App\Identity\Data\MfaRecoveryCodesData;
use App\Identity\Exceptions\MfaCodeInvalidException;
use App\Identity\Exceptions\MfaNotEnrolledException;
use App\Identity\Models\MfaRecoveryCode;
use App\Identity\Support\RecoveryCodeGenerator;
use App\Identity\Support\RecoveryCodeHasher;
use App\Identity\Support\TotpVerifier;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * POST /v1/auth/mfa/enrollment/confirm (stage-03 plan, MFA endpoint
 * table). Verifies the presented code against the pending secret
 * EnrollMfa stored, flips mfa_enabled and mfa_confirmed_at, and issues a
 * fresh batch of recovery codes: their plaintext is returned in this
 * response only and never again, since only the sha256 digest
 * (RecoveryCodeHasher) is ever persisted. Any recovery codes left over
 * from an earlier confirmation are cleared first, so re-confirming (a
 * fresh enrollment after EnrollMfa replaced the pending secret) cannot
 * leave stale codes valid against a secret they were never issued for.
 */
final class ConfirmMfaEnrollment
{
    public function __construct(private readonly TotpVerifier $totp) {}

    public function __invoke(User $user, ConfirmMfaData $data): MfaRecoveryCodesData
    {
        if ($user->mfa_secret === null) {
            throw MfaNotEnrolledException::make();
        }

        if (! $this->totp->verify($user->mfa_secret, $data->code)) {
            throw MfaCodeInvalidException::make();
        }

        $plainCodes = array_map(
            fn () => RecoveryCodeGenerator::generate(),
            range(1, config()->integer('identity.mfa_recovery_code_count')),
        );

        DB::transaction(function () use ($user, $plainCodes): void {
            $user->forceFill([
                'mfa_enabled' => true,
                'mfa_confirmed_at' => Date::now(),
            ])->save();

            MfaRecoveryCode::query()->where('user_id', $user->id)->delete();

            foreach ($plainCodes as $plainCode) {
                MfaRecoveryCode::query()->create([
                    'user_id' => $user->id,
                    'code_hash' => RecoveryCodeHasher::hash($plainCode),
                ]);
            }
        });

        return new MfaRecoveryCodesData($plainCodes);
    }
}
