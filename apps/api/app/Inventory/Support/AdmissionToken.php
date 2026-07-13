<?php

namespace App\Inventory\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * The stateless admission token (stage-10 plan, Data model "Admission
 * token"; task breakdown item 8): HMAC-SHA256 over entrant_id, event_id,
 * tenant_id, and expires_at, mirroring App\Orders\Support\TicketQrCodec's
 * own base64url-JSON-with-embedded-signature precedent. Nothing is
 * stored anywhere; App\Inventory\Actions\GetQueueEntry signs a fresh
 * token from the admitted Redis state (App\Inventory\Support\OnSaleQueue
 * admittedKey's own score) on every poll response, so no secret ever
 * rests in Redis (stage-10 plan, Endpoints "GET /v1/storefront/
 * queue-entries/{entry}").
 *
 * The token's own key id is a literal string prefix ("{key_id}." before
 * the encoded payload, stage-10 plan Data model: "signature: HMAC-SHA256
 * with a key ID prefix so signing keys rotate without invalidating
 * in-flight tokens"), so verify() can select the right secret before
 * decoding anything the presenter might have tampered with. Config
 * carries at most two live secrets at once (config/onsale.php
 * admission_token: current and previous): rotating means promoting the
 * current key to previous and generating a new current one, so tokens
 * already issued under the outgoing key keep verifying until it is
 * retired by clearing the previous config entry, at which point every
 * token still bearing that key id verifies as an unknown-key rejection.
 */
final class AdmissionToken
{
    public static function issue(string $entrantId, string $eventId, string $tenantId, CarbonInterface $expiresAt): string
    {
        $keyId = config()->string('onsale.admission_token.current_key_id');
        $secret = self::secretForKeyId($keyId)
            ?? throw new RuntimeException('Admission token signing key is not configured; set ONSALE_ADMISSION_TOKEN_SIGNING_KEY.');

        $expiresAtIso = $expiresAt->toIso8601String();

        $body = [
            'entrant_id' => $entrantId,
            'event_id' => $eventId,
            'tenant_id' => $tenantId,
            'expires_at' => $expiresAtIso,
            'signature' => self::signature($entrantId, $eventId, $tenantId, $expiresAtIso, $secret),
        ];

        return $keyId.'.'.self::base64UrlEncode((string) json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * Returns the entrant id the token was issued for once every check
     * passes (key id known, signature valid under that key's secret,
     * event and tenant match, not yet expired against $now), or null on
     * any single failure, deliberately not distinguishing which one:
     * the caller renders the same admission_invalid problem either way
     * (stage-10 plan, Endpoints "POST /v1/storefront/holds").
     */
    public static function verify(string $token, string $eventId, string $tenantId, CarbonInterface $now): ?string
    {
        $parts = explode('.', $token, 2);

        if (count($parts) !== 2) {
            return null;
        }

        [$keyId, $encoded] = $parts;

        $secret = self::secretForKeyId($keyId);

        if ($secret === null) {
            return null;
        }

        $decoded = self::base64UrlDecode($encoded);

        if ($decoded === null) {
            return null;
        }

        try {
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($payload)) {
            return null;
        }

        $entrantId = $payload['entrant_id'] ?? null;
        $payloadEventId = $payload['event_id'] ?? null;
        $payloadTenantId = $payload['tenant_id'] ?? null;
        $expiresAtRaw = $payload['expires_at'] ?? null;
        $signature = $payload['signature'] ?? null;

        if (! is_string($entrantId) || ! is_string($payloadEventId) || ! is_string($payloadTenantId)
            || ! is_string($expiresAtRaw) || ! is_string($signature)) {
            return null;
        }

        if ($payloadEventId !== $eventId || $payloadTenantId !== $tenantId) {
            return null;
        }

        if (! hash_equals(self::signature($entrantId, $payloadEventId, $payloadTenantId, $expiresAtRaw, $secret), $signature)) {
            return null;
        }

        try {
            $expiresAt = Date::parse($expiresAtRaw);
        } catch (Throwable) {
            return null;
        }

        // The exact expiry instant counts as expired, mirroring
        // App\Identity\Support\InvitationToken's own ">=" precedent for
        // every other TTL in this codebase.
        if ($now->greaterThanOrEqualTo($expiresAt)) {
            return null;
        }

        return $entrantId;
    }

    private static function signature(string $entrantId, string $eventId, string $tenantId, string $expiresAtIso, string $secret): string
    {
        return hash_hmac('sha256', $entrantId.'|'.$eventId.'|'.$tenantId.'|'.$expiresAtIso, $secret);
    }

    /**
     * A key whose secret is unset or blank resolves to null, exactly like an
     * unknown key id: ONSALE_ADMISSION_TOKEN_SIGNING_KEY ships blank in
     * .env.example, and HMAC-ing under the empty string would leave every
     * admission token forgeable by anyone who knows the (public) key id.
     * Rejecting here fails the whole gate closed rather than open.
     */
    private static function secretForKeyId(string $keyId): ?string
    {
        /** @var array{current_key_id: string, current_key_secret: ?string, previous_key_id: ?string, previous_key_secret: ?string} $config */
        $config = config('onsale.admission_token');

        if ($keyId === $config['current_key_id']) {
            return self::usableSecret($config['current_key_secret']);
        }

        if ($config['previous_key_id'] !== null && $keyId === $config['previous_key_id']) {
            return self::usableSecret($config['previous_key_secret']);
        }

        return null;
    }

    private static function usableSecret(?string $secret): ?string
    {
        return $secret === null || $secret === '' ? null : $secret;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
