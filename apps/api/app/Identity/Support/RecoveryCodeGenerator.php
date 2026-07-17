<?php

namespace App\Identity\Support;

use Illuminate\Support\Str;

/**
 * Generates a single plaintext MFA recovery code (stage-03 plan, MFA
 * endpoint table: MfaRecoveryCodesData.recovery_codes). Four groups of
 * four uppercase alphanumeric characters separated by dashes: long enough
 * (16 characters of entropy, Str::random draws from
 * Illuminate\Support\Str's cryptographically secure source) to resist
 * guessing, and grouped for a human to transcribe accurately, mirroring
 * the format already used as a test fixture in RecoveryCodeConsumptionTest.
 */
final class RecoveryCodeGenerator
{
    public static function generate(): string
    {
        return implode('-', [
            Str::upper(Str::random(4)),
            Str::upper(Str::random(4)),
            Str::upper(Str::random(4)),
            Str::upper(Str::random(4)),
        ]);
    }
}
