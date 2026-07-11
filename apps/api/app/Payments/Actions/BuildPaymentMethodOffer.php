<?php

namespace App\Payments\Actions;

use App\EventCatalog\Actions\ResolveAsyncPaymentPolicy;
use App\Inventory\Actions\GetEventAvailability;
use App\Inventory\Data\TicketTypeAvailabilityData;
use App\Orders\Data\OrderPaymentContextData;
use App\Payments\Data\PaymentMethodOfferData;
use App\Payments\Gateways\GatewayRegistry;
use App\Payments\Support\CircuitBreaker;
use App\Payments\Support\OfferAssembler;
use App\Support\Tenancy\TenantContext;
use App\Tenancy\Actions\ResolveEnabledGateways;

/**
 * The payment method offer for an order (stage-08a plan, Endpoints;
 * system-design 7.2, 7.4): enabled gateways come from Tenancy, the
 * slow-method policy from EventCatalog, and remaining inventory from
 * Inventory, all through Actions, never through another context's
 * tables. Enabled identifiers with no registered adapter are skipped:
 * ConfigureGateways deliberately defers adapter validation to here.
 */
final class BuildPaymentMethodOffer
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ResolveEnabledGateways $enabledGateways,
        private readonly ResolveAsyncPaymentPolicy $asyncPaymentPolicy,
        private readonly GetEventAvailability $availability,
        private readonly GatewayRegistry $gateways,
        private readonly OfferAssembler $assembler,
        private readonly CircuitBreaker $breaker,
    ) {}

    /**
     * An open breaker removes that gateway's methods from the offer
     * instead of degrading the whole checkout (system-design 13);
     * initiation passes false so it can distinguish an open breaker
     * (503 gateway_unavailable) from a method that was never offered
     * (422 payment_method_not_available).
     *
     * @return list<PaymentMethodOfferData>
     */
    public function __invoke(OrderPaymentContextData $order, bool $excludeOpenBreakers = true): array
    {
        $capabilities = [];

        foreach (($this->enabledGateways)((string) $this->tenantContext->tenantId()) as $identifier) {
            if ($excludeOpenBreakers && $this->breaker->isOpen($identifier)) {
                continue;
            }

            $adapter = $this->gateways->get($identifier);

            if ($adapter !== null) {
                $capabilities[$identifier] = $adapter->capabilities();
            }
        }

        if ($capabilities === []) {
            return [];
        }

        return $this->assembler->assemble(
            $capabilities,
            $order->total->currency,
            ($this->asyncPaymentPolicy)($order->eventId),
            $this->remainingInventory($order->eventId),
            (int) config('payments.low_inventory_cutoff'),
        );
    }

    private function remainingInventory(string $eventId): int
    {
        $availability = ($this->availability)($eventId);

        return array_sum(array_map(
            fn (TicketTypeAvailabilityData $item): int => $item->available,
            $availability->ticketTypes,
        ));
    }
}
