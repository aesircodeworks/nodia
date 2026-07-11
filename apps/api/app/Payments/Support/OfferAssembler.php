<?php

namespace App\Payments\Support;

use App\EventCatalog\Data\AsyncPaymentPolicyData;
use App\Payments\Data\PaymentMethodOfferData;
use App\Payments\Gateways\GatewayCapabilities;
use App\Payments\Gateways\MethodCapability;

/**
 * The offer as a pure function of gateway capabilities, order currency,
 * event policy, and inventory level (stage-08a plan, Slice 3;
 * system-design 7.2, 7.4). Async confirmation is what makes a method
 * slow: it holds inventory across a confirmation window, which is
 * exactly what the policy and the low-inventory cutoff exist to bound.
 */
final class OfferAssembler
{
    /**
     * @param  array<string, GatewayCapabilities>  $capabilitiesByGateway
     * @return list<PaymentMethodOfferData>
     */
    public function assemble(
        array $capabilitiesByGateway,
        string $currency,
        AsyncPaymentPolicyData $policy,
        int $remainingInventory,
        int $defaultCutoff,
    ): array {
        $offer = [];

        foreach ($capabilitiesByGateway as $gateway => $capabilities) {
            if (! $capabilities->supportsCurrency($currency)) {
                continue;
            }

            foreach ($capabilities->methods as $capability) {
                if ($capability->isAsync() && $this->slowMethodsExcluded($policy, $remainingInventory, $defaultCutoff)) {
                    continue;
                }

                $offer[] = PaymentMethodOfferData::fromCapability($gateway, $capability);
            }
        }

        return $offer;
    }

    public function allows(
        MethodCapability $capability,
        AsyncPaymentPolicyData $policy,
        int $remainingInventory,
        int $defaultCutoff,
    ): bool {
        return ! ($capability->isAsync() && $this->slowMethodsExcluded($policy, $remainingInventory, $defaultCutoff));
    }

    private function slowMethodsExcluded(AsyncPaymentPolicyData $policy, int $remainingInventory, int $defaultCutoff): bool
    {
        if (! $policy->slowMethodsEnabled) {
            return true;
        }

        return $remainingInventory <= ($policy->lowInventoryCutoff ?? $defaultCutoff);
    }
}
