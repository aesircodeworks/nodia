<?php

declare(strict_types=1);

namespace Tests\Support;

use PragmaRX\Google2FA\Google2FA;

/**
 * Computes the current TOTP code for a secret using the same library
 * App\Identity\Support\TotpVerifier wraps, for feature tests that need a
 * real, currently valid code (stage-03 plan, task breakdown item 11)
 * rather than asserting only against invalid ones.
 */
final class TotpCodes
{
    public static function current(string $secret): string
    {
        return (new Google2FA)->getCurrentOtp($secret);
    }
}
