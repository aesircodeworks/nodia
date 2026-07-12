<?php

namespace App\Payments\Gateways;

use App\Payments\Exceptions\GatewayUnavailableException;
use App\Payments\Exceptions\WebhookSignatureInvalidException;
use App\Payments\Exceptions\WebhookUnparseableException;
use Carbon\CarbonImmutable;

/**
 * The gateway seam (system-design 7.2). Everything in this stage runs
 * against FakeGateway; a real adapter (Stage 8d) is a swap-in behind
 * this interface. Adapters normalize gateway-specific shapes at the
 * boundary: the rest of the Payments context only ever sees
 * GatewayPaymentResult and NormalizedPaymentEvent.
 */
interface GatewayAdapter
{
    public function identifier(): string;

    public function capabilities(): GatewayCapabilities;

    /**
     * @throws GatewayUnavailableException on transport failure; a decline
     *                                     is a Declined result, never an exception
     */
    public function createPayment(GatewayPaymentRequest $request): GatewayPaymentResult;

    /**
     * Verifies the signature and extracts the gateway's own event id.
     *
     * @param  array<string, string>  $headers
     *
     * @throws WebhookSignatureInvalidException
     * @throws WebhookUnparseableException
     */
    public function parseWebhook(string $body, array $headers): ParsedWebhook;

    /**
     * Maps a persisted raw payload to a normalized payment event, or null
     * when the event type carries no payment consequence.
     *
     * @param  array<string, mixed>  $payload
     */
    public function normalizeWebhook(array $payload): ?NormalizedPaymentEvent;

    /**
     * Poller backstop for missed webhooks (system-design 13): the current
     * gateway-side outcome for a reference, or null while still pending.
     */
    public function queryPayment(string $gatewayReference): ?NormalizedPaymentEvent;

    /**
     * Submits a refund for asynchronous processing (system-design 7.2;
     * stage-08b plan). The request carries the refund's idempotency key
     * on every attempt.
     *
     * @throws GatewayUnavailableException on transport failure; a decline
     *                                     is a Declined result, never an exception
     */
    public function refund(GatewayRefundRequest $request): GatewayRefundResult;

    /**
     * Sweeper backstop for missed refund webhooks: the current
     * gateway-side outcome for a refund reference, or null while still
     * processing.
     */
    public function queryRefund(string $gatewayReference): ?NormalizedPaymentEvent;

    /**
     * Starts Sub-merchant onboarding for a tenant (system-design 7.3).
     * The gateway owns the KYC flow; this only starts it and returns
     * whatever it already knows (a reference, an onboarding URL when
     * buyer-side action is still needed, an immediate decision when the
     * gateway grants one synchronously).
     *
     * @throws GatewayUnavailableException on transport failure
     */
    public function createSubmerchant(SubmerchantRegistrationRequest $request): GatewaySubmerchantResult;

    /**
     * Poller and manual-refresh backstop for missed Sub-merchant status
     * webhooks (system-design 13).
     *
     * @throws GatewayUnavailableException on transport failure
     */
    public function fetchSubmerchantStatus(string $gatewayAccountReference): GatewaySubmerchantResult;

    /**
     * Poller backstop for missed payout webhooks (system-design 13):
     * payouts the gateway executed, optionally since a given instant.
     *
     * @return list<GatewayPayoutRecord>
     *
     * @throws GatewayUnavailableException on transport failure
     */
    public function listPayouts(?CarbonImmutable $since = null): array;
}
