<?php

namespace Tests\Support\Payments;

use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\GatewayAdapter;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Date;

/**
 * The probe every registered gateway slug is held to by the webhook
 * negative matrix (stage-12 plan, Slice 6). Adding an adapter to the
 * GatewayRegistry without adding it here fails the matrix's completeness
 * case, so a real gateway (Stage 8d's adapter, when it lands) cannot
 * merge without proving its verifier rejects the same four forgeries.
 */
final class WebhookNegativeProbes
{
    public static function for(string $slug): ?WebhookNegativeProbe
    {
        return self::all()[$slug] ?? null;
    }

    /**
     * @return array<string, WebhookNegativeProbe>
     */
    public static function all(): array
    {
        return [
            FakeGateway::IDENTIFIER => self::fake(),
            'pending' => self::pending(),
        ];
    }

    private static function fake(): WebhookNegativeProbe
    {
        return new WebhookNegativeProbe(
            validDelivery: fn (GatewayAdapter $adapter): array => self::fakeDelivery($adapter),
            missingSignature: function (GatewayAdapter $adapter): array {
                $delivery = self::fakeDelivery($adapter);

                unset($delivery['headers']['X-Fake-Signature']);

                return $delivery;
            },
            tamperedBody: function (GatewayAdapter $adapter): array {
                $delivery = self::fakeDelivery($adapter);

                /** @var array<string, mixed> $payload */
                $payload = json_decode($delivery['body'], associative: true);
                $payload['fee'] = ['amount' => 1, 'currency' => 'USD'];

                return [
                    'body' => (string) json_encode($payload, JSON_UNESCAPED_SLASHES),
                    'headers' => $delivery['headers'],
                ];
            },
            wrongKey: function (GatewayAdapter $adapter): array {
                $delivery = self::fakeDelivery($adapter);

                return [
                    'body' => $delivery['body'],
                    'headers' => FakeGatewaySignature::headersFor(
                        $delivery['body'],
                        secret: 'a-key-this-gateway-never-issued',
                    ),
                ];
            },
            staleTimestamp: function (GatewayAdapter $adapter): array {
                $delivery = self::fakeDelivery($adapter);
                $tolerance = config()->integer('payments.gateways.fake.webhook_tolerance_seconds');

                return [
                    'body' => $delivery['body'],
                    'headers' => FakeGatewaySignature::headersFor(
                        $delivery['body'],
                        timestamp: Date::now()->getTimestamp() - $tolerance - 60,
                    ),
                ];
            },
        );
    }

    /**
     * The skeleton rejects every webhook by contract (stage-08d plan,
     * Slice 3: a registered gateway with no signing secret fails closed),
     * so its forgeries are only shaped like the fake's and there is no
     * positive control to run against it.
     */
    private static function pending(): WebhookNegativeProbe
    {
        $body = (string) json_encode(['id' => 'evt_pending_probe', 'type' => 'payment.confirmed']);

        $delivery = fn (array $headers): array => ['body' => $body, 'headers' => $headers];

        return new WebhookNegativeProbe(
            validDelivery: fn (): array => $delivery(['X-Pending-Signature' => 'whatever-the-caller-claims']),
            missingSignature: fn (): array => $delivery([]),
            tamperedBody: fn (): array => $delivery(['X-Pending-Signature' => 'signature-of-a-different-body']),
            wrongKey: fn (): array => $delivery(['X-Pending-Signature' => 'signed-with-a-foreign-key']),
            staleTimestamp: fn (): array => $delivery([
                'X-Pending-Timestamp' => (string) (Date::now()->getTimestamp() - 86400),
                'X-Pending-Signature' => 'signed-a-day-ago',
            ]),
            acceptsValidDelivery: false,
        );
    }

    /**
     * @return array{body: string, headers: array<string, string>}
     */
    private static function fakeDelivery(GatewayAdapter $adapter): array
    {
        assert($adapter instanceof FakeGateway);

        $delivery = $adapter->confirmationWebhook('fake_negative_matrix', Money::of(125, 'USD'));

        return ['body' => $delivery->body, 'headers' => $delivery->headers];
    }
}
