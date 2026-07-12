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

    private int $failRefunds = 0;

    /** @var list<string> */
    private array $refundDeclines = [];

    /** @var array<string, int> */
    private array $refundCalls = [];

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

    public function failNextRefund(int $times = 1): void
    {
        $this->failRefunds = $times;
    }

    public function consumeRefundFailure(): bool
    {
        if ($this->failRefunds === 0) {
            return false;
        }

        $this->failRefunds--;

        return true;
    }

    public function declineNextRefund(string $failureCode): void
    {
        $this->refundDeclines[] = $failureCode;
    }

    public function consumeRefundDecline(): ?string
    {
        return array_shift($this->refundDeclines);
    }

    public function recordRefundCall(string $refundId): void
    {
        $this->refundCalls[$refundId] = ($this->refundCalls[$refundId] ?? 0) + 1;
    }

    public function refundCallsFor(string $refundId): int
    {
        return $this->refundCalls[$refundId] ?? 0;
    }
}
