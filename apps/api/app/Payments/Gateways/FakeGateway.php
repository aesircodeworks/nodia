<?php

namespace App\Payments\Gateways;

use App\Payments\Data\NextActionData;
use App\Payments\Enums\PaymentMethodConfirmation;
use App\Payments\Enums\PayoutStatus;
use App\Payments\Enums\SubmerchantStatus;
use App\Payments\Exceptions\GatewayUnavailableException;
use App\Payments\Exceptions\WebhookSignatureInvalidException;
use App\Payments\Exceptions\WebhookUnparseableException;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
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

    /**
     * The signature covers "{timestamp}.{body}", the shape real gateways
     * sign (system-design 7.4), so a captured delivery cannot be replayed
     * once its timestamp falls outside the configured tolerance and a
     * caller cannot backdate one without the secret.
     */
    private const TIMESTAMP_HEADER = 'X-Fake-Timestamp';

    private readonly SubmerchantStatusMap $submerchantStatusMap;

    public function __construct(
        private readonly FakeGatewayScenarios $scenarios,
        private readonly string $identifier = self::IDENTIFIER,
        ?SubmerchantStatusMap $submerchantStatusMap = null,
    ) {
        $this->submerchantStatusMap = $submerchantStatusMap ?? SubmerchantStatusMap::identity();
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    /**
     * Exposes the scenario controls bound to this instance so test
     * harnesses (the adapter conformance suite in particular) can arrange
     * a scripted outcome for the same adapter they hold a reference to.
     */
    public function scenarios(): FakeGatewayScenarios
    {
        return $this->scenarios;
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
            splitSupport: (bool) config('payments.gateways.fake.split_support', false),
        );
    }

    public function createPayment(GatewayPaymentRequest $request): GatewayPaymentResult
    {
        if ($this->scenarios->consumeCreateFailure()) {
            throw GatewayUnavailableException::forGateway($this->identifier);
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
        $timestamp = $this->headerValue($headers, self::TIMESTAMP_HEADER);

        if ($signature === null || $timestamp === null || ! ctype_digit($timestamp)) {
            throw WebhookSignatureInvalidException::forGateway($this->identifier);
        }

        if (! hash_equals($this->signatureFor($timestamp, $body), $signature)) {
            throw WebhookSignatureInvalidException::forGateway($this->identifier);
        }

        $age = abs(Date::now()->getTimestamp() - (int) $timestamp);

        if ($age > config()->integer('payments.gateways.fake.webhook_tolerance_seconds')) {
            throw WebhookSignatureInvalidException::forGateway($this->identifier);
        }

        $payload = json_decode($body, associative: true);

        if (! is_array($payload) || ! is_string($payload['id'] ?? null) || $payload['id'] === '') {
            throw WebhookUnparseableException::forGateway($this->identifier);
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
            'payment.confirmed' => $this->normalizedConfirmation($reference, $payload),
            'payment.failed' => NormalizedPaymentEvent::failed($reference, (string) ($payload['failure_code'] ?? 'unknown')),
            'refund.completed' => NormalizedPaymentEvent::refundCompleted($reference),
            'refund.failed' => NormalizedPaymentEvent::refundFailed($reference, (string) ($payload['failure_code'] ?? 'unknown')),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function normalizedConfirmation(string $reference, array $payload): ?NormalizedPaymentEvent
    {
        $amount = $payload['fee']['amount'] ?? null;
        $currency = $payload['fee']['currency'] ?? null;

        // A fee that is not integer minor units with a valid currency is
        // never coerced; the event routes to the ignored path instead.
        if (! is_int($amount) || $amount < 0 || ! is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return null;
        }

        return NormalizedPaymentEvent::confirmed($reference, Money::of($amount, $currency));
    }

    public function normalizeSubmerchantWebhook(array $payload): ?NormalizedSubmerchantEvent
    {
        if (($payload['type'] ?? null) !== 'submerchant.status_changed') {
            return null;
        }

        $reference = $payload['reference'] ?? null;
        $rawStatus = $payload['status'] ?? null;
        $requirements = $payload['requirements'] ?? [];

        if (! is_string($reference) || $reference === '' || ! is_string($rawStatus) || $rawStatus === '' || ! is_array($requirements)) {
            return null;
        }

        $status = $this->submerchantStatusMap->resolve($rawStatus);

        return new NormalizedSubmerchantEvent($reference, $status, array_values(array_map('strval', $requirements)));
    }

    public function normalizePayoutWebhook(array $payload): ?NormalizedPayoutEvent
    {
        $accountReference = $payload['account_reference'] ?? null;
        $reference = $payload['reference'] ?? null;
        $status = PayoutStatus::tryFrom((string) ($payload['status'] ?? ''));

        if (! is_string($accountReference) || $accountReference === '' || ! is_string($reference) || $reference === '' || $status === null) {
            return null;
        }

        $rawExecutedAt = $payload['executed_at'] ?? null;

        if (is_string($rawExecutedAt)) {
            // A malformed timestamp on an untrusted webhook is treated as an
            // invalid payload (ignored), never allowed to throw and stall the
            // webhook job into indefinite retries.
            try {
                $executedAt = CarbonImmutable::parse($rawExecutedAt);
            } catch (\Throwable) {
                return null;
            }
        } else {
            $executedAt = null;
        }

        return match ($payload['type'] ?? null) {
            'payout.created' => $this->normalizedPayoutCreated($accountReference, $reference, $status, $payload),
            'payout.status_changed' => new NormalizedPayoutEvent($accountReference, $reference, $status, null, $executedAt),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function normalizedPayoutCreated(string $accountReference, string $reference, PayoutStatus $status, array $payload): ?NormalizedPayoutEvent
    {
        $amount = $payload['amount']['amount'] ?? null;
        $currency = $payload['amount']['currency'] ?? null;

        if (! is_int($amount) || $amount < 0 || ! is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return null;
        }

        return new NormalizedPayoutEvent($accountReference, $reference, $status, Money::of($amount, $currency), null);
    }

    public function queryPayment(string $gatewayReference): ?NormalizedPaymentEvent
    {
        return $this->scenarios->queryResultFor($gatewayReference);
    }

    public function refund(GatewayRefundRequest $request): GatewayRefundResult
    {
        $this->scenarios->recordRefundCall($request->refundId);

        if ($this->scenarios->consumeRefundFailure()) {
            throw GatewayUnavailableException::forGateway($this->identifier);
        }

        $decline = $this->scenarios->consumeRefundDecline();

        if ($decline !== null) {
            return GatewayRefundResult::declined($decline);
        }

        return GatewayRefundResult::accepted('fake_rf_'.$request->refundId);
    }

    public function queryRefund(string $gatewayReference): ?NormalizedPaymentEvent
    {
        return $this->scenarios->queryResultFor($gatewayReference);
    }

    public function refundCompletionWebhook(string $refundReference, ?string $eventId = null): FakeWebhookDelivery
    {
        return $this->sign([
            'id' => $eventId ?? 'evt_'.Str::uuid7(),
            'type' => 'refund.completed',
            'reference' => $refundReference,
        ]);
    }

    public function refundFailureWebhook(string $refundReference, string $failureCode, ?string $eventId = null): FakeWebhookDelivery
    {
        return $this->sign([
            'id' => $eventId ?? 'evt_'.Str::uuid7(),
            'type' => 'refund.failed',
            'reference' => $refundReference,
            'failure_code' => $failureCode,
        ]);
    }

    public function createSubmerchant(SubmerchantRegistrationRequest $request): GatewaySubmerchantResult
    {
        $this->scenarios->recordSubmerchantCreationCall($request->tenantId, $this->identifier);

        if ($this->scenarios->consumeSubmerchantCreationFailure()) {
            throw GatewayUnavailableException::forGateway($this->identifier);
        }

        $scripted = $this->scenarios->consumeSubmerchantCreation();

        if ($scripted !== null) {
            return $scripted;
        }

        $reference = 'fakesm_'.$request->tenantId;

        return GatewaySubmerchantResult::pending($reference, "https://fake-gateway.test/onboarding/{$reference}");
    }

    public function fetchSubmerchantStatus(string $gatewayAccountReference): GatewaySubmerchantResult
    {
        return $this->scenarios->submerchantStatusFor($gatewayAccountReference)
            ?? GatewaySubmerchantResult::pending($gatewayAccountReference, "https://fake-gateway.test/onboarding/{$gatewayAccountReference}");
    }

    public function listPayouts(?CarbonImmutable $since = null): array
    {
        if ($since === null) {
            return $this->scenarios->payouts();
        }

        return array_values(array_filter(
            $this->scenarios->payouts(),
            fn (GatewayPayoutRecord $record): bool => $record->executedAt !== null && $record->executedAt->greaterThan($since),
        ));
    }

    /**
     * @param  list<string>  $requirements
     */
    public function submerchantStatusWebhook(
        string $gatewayAccountReference,
        SubmerchantStatus $status,
        array $requirements = [],
        ?string $eventId = null,
    ): FakeWebhookDelivery {
        return $this->submerchantStatusWebhookRaw($gatewayAccountReference, $status->value, $requirements, $eventId);
    }

    /**
     * Builds a submerchant status webhook carrying a raw status string
     * rather than a normalized SubmerchantStatus, so tests can script an
     * adapter's own (possibly unmapped) vocabulary through the same
     * webhook path a real gateway's payload would take (stage-08d plan,
     * Slice 4).
     *
     * @param  list<string>  $requirements
     */
    public function submerchantStatusWebhookRaw(
        string $gatewayAccountReference,
        string $rawStatus,
        array $requirements = [],
        ?string $eventId = null,
    ): FakeWebhookDelivery {
        return $this->sign([
            'id' => $eventId ?? 'evt_'.Str::uuid7(),
            'type' => 'submerchant.status_changed',
            'reference' => $gatewayAccountReference,
            'status' => $rawStatus,
            'requirements' => $requirements,
        ]);
    }

    public function payoutCreatedWebhook(
        string $gatewayAccountReference,
        string $gatewayReference,
        Money $amount,
        ?string $eventId = null,
    ): FakeWebhookDelivery {
        return $this->sign([
            'id' => $eventId ?? 'evt_'.Str::uuid7(),
            'type' => 'payout.created',
            'account_reference' => $gatewayAccountReference,
            'reference' => $gatewayReference,
            'amount' => ['amount' => $amount->amount, 'currency' => $amount->currency],
            'status' => PayoutStatus::Pending->value,
        ]);
    }

    public function payoutStatusWebhook(
        string $gatewayAccountReference,
        string $gatewayReference,
        PayoutStatus $status,
        ?CarbonImmutable $executedAt = null,
        ?string $eventId = null,
    ): FakeWebhookDelivery {
        return $this->sign([
            'id' => $eventId ?? 'evt_'.Str::uuid7(),
            'type' => 'payout.status_changed',
            'account_reference' => $gatewayAccountReference,
            'reference' => $gatewayReference,
            'status' => $status->value,
            'executed_at' => $executedAt?->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sign(array $payload): FakeWebhookDelivery
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) Date::now()->getTimestamp();

        return new FakeWebhookDelivery($body, [
            self::TIMESTAMP_HEADER => $timestamp,
            self::SIGNATURE_HEADER => $this->signatureFor($timestamp, $body),
        ]);
    }

    private function signatureFor(string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, (string) config('payments.gateways.fake.webhook_secret'));
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
