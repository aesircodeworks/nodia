<?php

namespace App\Identity\Actions;

use App\Identity\Models\MfaRecoveryCode;
use App\Identity\Support\RecoveryCodeHasher;
use Illuminate\Support\Facades\Date;

/**
 * Atomically consumes one of a user's MFA recovery codes (stage-03 plan,
 * Data model "mfa_recovery_codes"; task breakdown item 10's design pin,
 * task breakdown item 11). A conditional UPDATE checked by affected-row
 * count, never a read-then-write existence check first (master plan
 * test-first rule 2; CLAUDE.md): two parallel presentations of the same
 * code can only ever see the row transition from unused to used once,
 * proven by tests/Concurrency/RecoveryCodeConsumptionContentionTest.php.
 */
final class ConsumeRecoveryCode
{
    public function __invoke(string $userId, string $plainCode): bool
    {
        $affected = MfaRecoveryCode::query()
            ->where('user_id', $userId)
            ->where('code_hash', RecoveryCodeHasher::hash($plainCode))
            ->whereNull('used_at')
            ->update(['used_at' => Date::now()]);

        return $affected > 0;
    }
}
