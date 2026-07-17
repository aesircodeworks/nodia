<?php

namespace App\Payments\Actions;

use App\Payments\Exceptions\GatewayUnavailableException;
use App\Payments\Exceptions\SubmerchantAccountNotFoundException;
use App\Payments\Gateways\GatewayRegistry;
use App\Payments\Models\SubmerchantAccount;
use App\Payments\Support\CircuitBreaker;

/**
 * POST /v1/submerchant-accounts/{id}/refresh (stage-08c plan, Endpoints):
 * the manual fallback for a missed status webhook. Pulls the gateway's
 * current view with fetchSubmerchantStatus and applies the exact same
 * conditional transition the webhook path uses, so a webhook and a
 * refresh racing the same account converge on one winner.
 */
final class RefreshSubmerchantStatus
{
    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly CircuitBreaker $breaker,
        private readonly TransitionSubmerchantAccount $transition,
    ) {}

    public function __invoke(string $accountId): SubmerchantAccount
    {
        $account = SubmerchantAccount::query()->find($accountId)
            ?? throw SubmerchantAccountNotFoundException::forId($accountId);

        if ($account->gateway_account_reference === null) {
            return $account;
        }

        $adapter = $this->gateways->get($account->gateway)
            ?? throw SubmerchantAccountNotFoundException::forId($accountId);

        if (! $this->breaker->allowsRequest($account->gateway)) {
            throw GatewayUnavailableException::forGateway($account->gateway);
        }

        try {
            $result = $adapter->fetchSubmerchantStatus($account->gateway_account_reference);
        } catch (GatewayUnavailableException $e) {
            $this->breaker->recordFailure($account->gateway);

            throw $e;
        }

        $this->breaker->recordSuccess($account->gateway);

        ($this->transition)($account->id, $result->status, $result->requirements);

        return SubmerchantAccount::query()->findOrFail($account->id);
    }
}
