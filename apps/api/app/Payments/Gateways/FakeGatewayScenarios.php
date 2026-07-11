<?php

namespace App\Payments\Gateways;

/**
 * Scenario controls for FakeGateway, injected and container-scoped so
 * parallel tests never share state through a global (Octane discipline,
 * system-design 16.2). Tests script transport failures and poller
 * answers here; everything else FakeGateway derives deterministically
 * from the initiation request itself.
 */
final class FakeGatewayScenarios
{
    private int $failCreates = 0;

    /** @var array<string, NormalizedPaymentEvent> */
    private array $queryResults = [];

    public function failNextCreate(int $times = 1): void
    {
        $this->failCreates = $times;
    }

    public function consumeCreateFailure(): bool
    {
        if ($this->failCreates === 0) {
            return false;
        }

        $this->failCreates--;

        return true;
    }

    public function scriptQueryResult(string $gatewayReference, NormalizedPaymentEvent $event): void
    {
        $this->queryResults[$gatewayReference] = $event;
    }

    public function queryResultFor(string $gatewayReference): ?NormalizedPaymentEvent
    {
        return $this->queryResults[$gatewayReference] ?? null;
    }
}
