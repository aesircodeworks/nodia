<?php

namespace App\Identity\Support;

use Illuminate\Support\Facades\Date;
use PragmaRX\Google2FA\Google2FA;

/**
 * Thin wrapper over pragmarx/google2fa (RFC 6238 TOTP), stage-03 plan
 * task breakdown item 11 and Slice 5's "TOTP verification window under
 * the fake clock" unit test. Google2FA::verifyKey() defaults to the
 * current wall-clock period via microtime(true) when no timestamp is
 * passed, which the Stage 1 fake clock (Carbon::setTestNow /
 * freezeTime() / travel()) cannot control; every call here instead
 * derives the period counter from Date::now(), the framework clock
 * tests/Architecture/TimeSourceTest.php requires app code to use, so
 * travelling the fake clock genuinely moves which codes verify.
 */
final class TotpVerifier
{
    public function __construct(private readonly Google2FA $engine) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    /**
     * otpauth:// URI for an authenticator app to scan or import
     * (MfaEnrollmentData.otpauth_uri). $label identifies the account
     * within the issuer, conventionally the user's email.
     */
    public function otpauthUri(string $secret, string $label): string
    {
        return $this->engine->getQRCodeUrl(config()->string('app.name'), $label, $secret);
    }

    /**
     * Verifies a TOTP code against $secret using Google2FA's default
     * window (one period past and future, so a code stays valid across
     * clock drift and input delay), evaluated at the framework clock's
     * current period rather than PHP's uncontrollable wall clock.
     */
    public function verify(string $secret, string $code): bool
    {
        return $this->engine->verifyKey($secret, $code, timestamp: $this->currentPeriod()) !== false;
    }

    private function currentPeriod(): int
    {
        return intdiv(Date::now()->getTimestamp(), $this->engine->getKeyRegeneration());
    }
}
