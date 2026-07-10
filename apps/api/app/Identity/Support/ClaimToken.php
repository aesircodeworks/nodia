<?php

namespace App\Identity\Support;

use App\Identity\Exceptions\ClaimTokenExpiredException;
use App\Identity\Exceptions\ClaimTokenInvalidException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use JsonException;
use Throwable;

/**
 * The claim-by-email-verification token for POST /v1/auth/customer/claim
 * and POST /v1/auth/customer/claim/confirm (stage-03 plan, task breakdown
 * item 13; system-design 5.2, ADR 007). Mirrors
 * App\Identity\Support\InvitationToken's own precedent exactly and for
 * the same reasons: deliberately stateless, the payload (the guest
 * customer's id and an expiry instant) sealed with the application's own
 * encrypter, which gives both the tamper-evidence and the time limit the
 * plan asks for without a database row to track. No single-use tracking
 * here either: the plan's error code list for the confirm endpoint is
 * claim_token_invalid, claim_token_expired, and customer_already_claimed,
 * no "reused" code; the third of those is what makes a replayed
 * still-valid token harmless, since ClaimGuestAccount checks the
 * customer's own password column, not the token, for single use.
 */
final class ClaimToken
{
    public static function issue(string $customerId): string
    {
        $payload = [
            'customer_id' => $customerId,
            'expires_at' => Date::now()
                ->addMinutes(config()->integer('identity.claim_token_ttl_minutes'))
                ->toIso8601String(),
        ];

        return Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @return string the claimed customer's id
     */
    public static function verify(string $token): string
    {
        try {
            /** @var mixed $payload */
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw ClaimTokenInvalidException::make();
        }

        if (! is_array($payload) || ! is_string($payload['customer_id'] ?? null) || ! is_string($payload['expires_at'] ?? null)) {
            throw ClaimTokenInvalidException::make();
        }

        try {
            $expiresAt = Date::parse($payload['expires_at']);
        } catch (Throwable) {
            throw ClaimTokenInvalidException::make();
        }

        // The exact expiry instant counts as expired, matching
        // tests/Unit/Time/FrameworkClockTest.php's own ">=" precedent for
        // every other TTL in this codebase.
        if (Date::now()->greaterThanOrEqualTo($expiresAt)) {
            throw ClaimTokenExpiredException::make();
        }

        return $payload['customer_id'];
    }
}
