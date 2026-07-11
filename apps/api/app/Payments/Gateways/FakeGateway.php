<?php

namespace App\Payments\Gateways;

use App\Payments\Data\NextActionData;
use App\Payments\Enums\PaymentMethodConfirmation;
use App\Payments\Exceptions\GatewayUnavailableException;
use App\Payments\Exceptions\WebhookSignatureInvalidException;
use App\Payments\Exceptions\WebhookUnparseableException;
use App\Support\Money\Money;
use Illuminate\Support\Str;

/**
 * The in-repo adapter every 8a behavior is built and tested against
 * (master plan "Payment gateway posture"). Outcomes are deterministic
 * functions of the request: card tokens select approve or decline, async
 * methods always pend, and the webhook emitter produces HMAC-signed
 * payloads this same adapter verifies, so tests script every failure
 * mode without a network.
 */
final class FakeGateway implements GatewayAdapter
{
    public const IDENTIFIER = 'fake';

    private const SIGNATURE_HEADER = 'X-Fake-Signature';

    public function __construct(
        private readonly FakeGatewayScenarios $scenarios,
    ) {}

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function capabilities(): GatewayCapabilities
    {
        return new GatewayCapabilities(
            methods: [
                new MethodCapability('card', PaymentMethodConfirmation::Sync, null),
                new MethodCapability('pix', PaymentMethodConfirmation::Async, (int) config('payments.gateways.fake.windows.pix')),
                new MethodCapability('boleto', PaymentMethodConfirmation::Async, (int) config('payments.gateways.fake.windows.boleto')),
            ],
            currencies: config('payments.gateways.fake.currencies'),
            asyncConfirmation: true,
            splitSupport: false,
        );
    }

    public function createPayment(GatewayPaymentRequest $request): GatewayPaymentResult
    {
        if ($this->scenarios->consumeCreateFailure()) {
            throw GatewayUnavailableException::forGateway(self::IDENTIFIER);
        }

        $reference = 'fake_'.$request->paymentId;

        if ($request->method === 'card') {
            return match ($request->details['token'] ?? null) {
                'tok_approve' => GatewayPaymentResult::approved($reference, $this->feeFor($request->amount)),
                'tok_decline' => GatewayPaymentResult::declined($reference, 'card_declined'),
                default => GatewayPaymentResult::declined($reference, 'token_invalid'),
            };
        }

        return GatewayPaymentResult::pending($reference, NextActionData::displayCode(
            strtoupper($request->method).'-'.$request->paymentId,
        ));
    }

    public function feeFor(Money $amount): Money
    {
        $bps = (int) config('payments.gateways.fake.fee_bps');

        return Money::of(intdiv($amount->amount * $bps, 10000), $amount->currency);
    }

    public function confirmationWebhook(string $gatewayReference, Money $fee, ?string $eventId = null): FakeWebhookDelivery
    {
        return $this->sign([
            'id' => $eventId ?? 'evt_'.Str::uuid7(),
            'type' => 'payment.confirmed',
            'reference' => $gatewayReference,
            'fee' => ['amount' => $fee->amount, 'currency' => $fee->currency],
        ]);
    }

    public function failureWebhook(string $gatewayReference, string $failureCode, ?string $eventId = null): FakeWebhookDelivery
    {
        return $this->sign([
            'id' => $eventId ?? 'evt_'.Str::uuid7(),
            'type' => 'payment.failed',
            'reference' => $gatewayReference,
            'failure_code' => $failureCode,
        ]);
    }

    public function parseWebhook(string $body, array $headers): ParsedWebhook
    {
        $signature = $this->headerValue($headers, self::SIGNATURE_HEADER);

        if ($signature === null || ! hash_equals($this->signatureFor($body), $signature)) {
            throw WebhookSignatureInvalidException::forGateway(self::IDENTIFIER);
        }

        $payload = json_decode($body, associative: true);

        if (! is_array($payload) || ! is_string($payload['id'] ?? null) || $payload['id'] === '') {
            throw WebhookUnparseableException::forGateway(self::IDENTIFIER);
        }

        return new ParsedWebhook($payload['id'], $payload);
    }

    public function normalizeWebhook(array $payload): ?NormalizedPaymentEvent
    {
        $reference = $payload['reference'] ?? null;

        if (! is_string($reference) || $reference === '') {
            return null;
        }

        return match ($payload['type'] ?? null) {
            'payment.confirmed' => NormalizedPaymentEvent::confirmed($reference, Money::of(
                (int) ($payload['fee']['amount'] ?? 0),
                (string) ($payload['fee']['currency'] ?? 'BRL'),
            )),
            'payment.failed' => NormalizedPaymentEvent::failed($reference, (string) ($payload['failure_code'] ?? 'unknown')),
            default => null,
        };
    }

    public function queryPayment(string $gatewayReference): ?NormalizedPaymentEvent
    {
        return $this->scenarios->queryResultFor($gatewayReference);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sign(array $payload): FakeWebhookDelivery
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        return new FakeWebhookDelivery($body, [self::SIGNATURE_HEADER => $this->signatureFor($body)]);
    }

    private function signatureFor(string $body): string
    {
        return hash_hmac('sha256', $body, (string) config('payments.gateways.fake.webhook_secret'));
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }
}
