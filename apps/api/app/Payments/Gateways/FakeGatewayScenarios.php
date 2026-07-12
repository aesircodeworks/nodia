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

    /** @var list<GatewaySubmerchantResult> */
    private array $submerchantCreations = [];

    /** @var array<string, GatewaySubmerchantResult> */
    private array $submerchantStatuses = [];

    /** @var array<string, int> */
    private array $submerchantCreationCalls = [];

    /** @var list<GatewayPayoutRecord> */
    private array $payouts = [];

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

    /**
     * Queues the outcome the next createSubmerchant call returns; consumed
     * FIFO so a test can script a sequence of onboarding attempts.
     */
    public function scriptSubmerchantCreation(GatewaySubmerchantResult $result): void
    {
        $this->submerchantCreations[] = $result;
    }

    public function consumeSubmerchantCreation(): ?GatewaySubmerchantResult
    {
        return array_shift($this->submerchantCreations);
    }

    /**
     * Scripts what fetchSubmerchantStatus returns for a given reference,
     * simulating webhook-equivalent gateway-side transitions the poller
     * or refresh endpoint would observe.
     */
    public function scriptSubmerchantStatus(string $gatewayAccountReference, GatewaySubmerchantResult $result): void
    {
        $this->submerchantStatuses[$gatewayAccountReference] = $result;
    }

    public function submerchantStatusFor(string $gatewayAccountReference): ?GatewaySubmerchantResult
    {
        return $this->submerchantStatuses[$gatewayAccountReference] ?? null;
    }

    /**
     * Records one createSubmerchant call for a tenant and gateway pair,
     * the side effect the duplicate-onboarding-start concurrency test
     * asserts stays at exactly one for the winner (stage-08c plan,
     * Slice 2).
     */
    public function recordSubmerchantCreationCall(string $tenantId, string $gateway): void
    {
        $key = $tenantId.':'.$gateway;
        $this->submerchantCreationCalls[$key] = ($this->submerchantCreationCalls[$key] ?? 0) + 1;
    }

    public function submerchantCreationCallCountFor(string $tenantId, string $gateway): int
    {
        return $this->submerchantCreationCalls[$tenantId.':'.$gateway] ?? 0;
    }

    /**
     * @param  list<GatewayPayoutRecord>  $records
     */
    public function scriptPayouts(array $records): void
    {
        $this->payouts = $records;
    }

    /**
     * @return list<GatewayPayoutRecord>
     */
    public function payouts(): array
    {
        return $this->payouts;
    }
}
