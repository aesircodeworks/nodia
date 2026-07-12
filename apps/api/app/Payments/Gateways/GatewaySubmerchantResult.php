<?php

namespace App\Payments\Gateways;

use App\Payments\Enums\SubmerchantStatus;

/**
 * The gateway's current view of a Sub-merchant registration, returned by
 * both createSubmerchant and fetchSubmerchantStatus (stage-08c plan,
 * Slice 1). Normalized here so the rest of Payments never sees a
 * gateway-specific shape.
 */
final class GatewaySubmerchantResult
{
    /**
     * @param  list<string>  $requirements
     */
    private function __construct(
        public readonly SubmerchantStatus $status,
        public readonly ?string $gatewayAccountReference,
        public readonly ?string $onboardingUrl,
        public readonly array $requirements,
    ) {}

    public static function pending(string $gatewayAccountReference, ?string $onboardingUrl = null): self
    {
        return new self(SubmerchantStatus::Pending, $gatewayAccountReference, $onboardingUrl, []);
    }

    public static function underReview(string $gatewayAccountReference): self
    {
        return new self(SubmerchantStatus::UnderReview, $gatewayAccountReference, null, []);
    }

    /**
     * @param  list<string>  $requirements
     */
    public static function actionRequired(string $gatewayAccountReference, array $requirements): self
    {
        return new self(SubmerchantStatus::ActionRequired, $gatewayAccountReference, null, $requirements);
    }

    public static function active(string $gatewayAccountReference): self
    {
        return new self(SubmerchantStatus::Active, $gatewayAccountReference, null, []);
    }

    public static function rejected(string $gatewayAccountReference): self
    {
        return new self(SubmerchantStatus::Rejected, $gatewayAccountReference, null, []);
    }
}
