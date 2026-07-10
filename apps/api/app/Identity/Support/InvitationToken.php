<?php

namespace App\Identity\Support;

use App\Identity\Exceptions\InvitationTokenExpiredException;
use App\Identity\Exceptions\InvitationTokenInvalidException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use JsonException;
use Throwable;

/**
 * The "signed, time-limited acceptance token" the endpoint table names for
 * POST /v1/memberships and POST /v1/auth/staff/invitation/accept
 * (stage-03 plan, task breakdown item 9). Deliberately stateless: the
 * payload (the invited user's id and an expiry instant) is sealed with
 * the application's own encrypter (AES-256-CBC plus an HMAC over the
 * ciphertext, Illuminate\Encryption\Encrypter), which gives both
 * properties the plan asks for without a database row to track. "Signed"
 * here means tamper-evident, not a JWS; no error code in the stage plan's
 * registry distinguishes the two, so Crypt::decryptString's own MAC
 * verification is exactly the tamper check the feature test exercises.
 * No single-use tracking: the plan's error code list for this endpoint is
 * only invitation_token_invalid and invitation_token_expired, no "reused"
 * code the way the later staff password reset flow (task breakdown item
 * 16) is expected to need one, so replaying a still-valid token before
 * expiry is accepted as a harmless no-op (it can only ever set the same
 * user's password again).
 */
final class InvitationToken
{
    public static function issue(string $userId): string
    {
        $payload = [
            'user_id' => $userId,
            'expires_at' => Date::now()
                ->addMinutes(config()->integer('identity.invitation_token_ttl_minutes'))
                ->toIso8601String(),
        ];

        return Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @return string the invited user's id
     */
    public static function verify(string $token): string
    {
        try {
            /** @var mixed $payload */
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw InvitationTokenInvalidException::make();
        }

        if (! is_array($payload) || ! is_string($payload['user_id'] ?? null) || ! is_string($payload['expires_at'] ?? null)) {
            throw InvitationTokenInvalidException::make();
        }

        try {
            $expiresAt = Date::parse($payload['expires_at']);
        } catch (Throwable) {
            throw InvitationTokenInvalidException::make();
        }

        // The exact expiry instant counts as expired, matching
        // tests/Unit/Time/FrameworkClockTest.php's own ">=" precedent for
        // every other TTL in this codebase.
        if (Date::now()->greaterThanOrEqualTo($expiresAt)) {
            throw InvitationTokenExpiredException::make();
        }

        return $payload['user_id'];
    }
}
