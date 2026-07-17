<?php

namespace App\Payments\Gateways;

use App\Payments\Exceptions\GatewayNotConfiguredException;
use App\Payments\Exceptions\WebhookSignatureInvalidException;
use Carbon\CarbonImmutable;

/**
 * The skeleton every registered-but-not-yet-implemented gateway resolves
 * to (stage-08d plan, Slice 3). Its capabilities declare it supports
 * nothing, so it never contributes a method to a checkout offer
 * (OfferAssembler skips a gateway whose capabilities do not support the
 * order currency); every other operation throws the typed
 * GatewayNotConfiguredException, distinct from GatewayUnavailableException
 * (a transient transport failure) because this is a permanent
 * configuration gap. The webhook verifier seam is the one exception:
 * this skeleton rejects every signature outright, matching the shape of
 * a real adapter whose signing secret is genuinely absent, so ingestion
 * for its slug fails closed with webhook_signature_invalid before
 * anything is persisted, never with gateway_not_configured.
 */
final class PendingGatewayAdapter implements GatewayAdapter
{
    public function __construct(private readonly string $identifier) {}

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function capabilities(): GatewayCapabilities
    {
        return new GatewayCapabilities(
            methods: [],
            currencies: [],
            asyncConfirmation: false,
            splitSupport: false,
        );
    }

    public function createPayment(GatewayPaymentRequest $request): GatewayPaymentResult
    {
        throw GatewayNotConfiguredException::forGateway($this->identifier);
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function parseWebhook(string $body, array $headers): ParsedWebhook
    {
        throw WebhookSignatureInvalidException::forGateway($this->identifier);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function normalizeWebhook(array $payload): ?NormalizedPaymentEvent
    {
        throw GatewayNotConfiguredException::forGateway($this->identifier);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function normalizeSubmerchantWebhook(array $payload): ?NormalizedSubmerchantEvent
    {
        throw GatewayNotConfiguredException::forGateway($this->identifier);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function normalizePayoutWebhook(array $payload): ?NormalizedPayoutEvent
    {
        throw GatewayNotConfiguredException::forGateway($this->identifier);
    }

    public function queryPayment(string $gatewayReference): ?NormalizedPaymentEvent
    {
        throw GatewayNotConfiguredException::forGateway($this->identifier);
    }

    public function refund(GatewayRefundRequest $request): GatewayRefundResult
    {
        throw GatewayNotConfiguredException::forGateway($this->identifier);
    }

    public function queryRefund(string $gatewayReference): ?NormalizedPaymentEvent
    {
        throw GatewayNotConfiguredException::forGateway($this->identifier);
    }

    public function createSubmerchant(SubmerchantRegistrationRequest $request): GatewaySubmerchantResult
    {
        throw GatewayNotConfiguredException::forGateway($this->identifier);
    }

    public function fetchSubmerchantStatus(string $gatewayAccountReference): GatewaySubmerchantResult
    {
        throw GatewayNotConfiguredException::forGateway($this->identifier);
    }

    /**
     * @return list<GatewayPayoutRecord>
     */
    public function listPayouts(?CarbonImmutable $since = null): array
    {
        throw GatewayNotConfiguredException::forGateway($this->identifier);
    }
}
