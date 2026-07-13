<?php

namespace Tests\Support\Payments;

use Illuminate\Support\Facades\Date;

/**
 * FakeGateway's signing scheme, reimplemented on the test side so a test
 * can sign a body the gateway itself would never emit (a payload with no
 * event id, say) and so the negative matrix can forge a signature with a
 * foreign key or a backdated timestamp. Keeping it here, rather than
 * widening the adapter's public surface, means the adapter is verified
 * against an independent statement of its scheme.
 */
final class FakeGatewaySignature
{
    /**
     * @return array<string, string>
     */
    public static function headersFor(string $body, ?int $timestamp = null, ?string $secret = null): array
    {
        $timestamp = (string) ($timestamp ?? Date::now()->getTimestamp());
        $secret ??= config()->string('payments.gateways.fake.webhook_secret');

        return [
            'X-Fake-Timestamp' => $timestamp,
            'X-Fake-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, $secret),
        ];
    }
}
