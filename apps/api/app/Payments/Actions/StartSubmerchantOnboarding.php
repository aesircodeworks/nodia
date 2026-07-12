<?php

namespace App\Payments\Actions;

use App\Payments\Data\StartSubmerchantOnboardingData;
use App\Payments\Enums\SubmerchantStatus;
use App\Payments\Exceptions\GatewayNotEnabledException;
use App\Payments\Exceptions\GatewayUnavailableException;
use App\Payments\Exceptions\GatewayUnknownException;
use App\Payments\Exceptions\SubmerchantAlreadyOnboardedException;
use App\Payments\Gateways\GatewayRegistry;
use App\Payments\Gateways\SubmerchantRegistrationRequest;
use App\Payments\Models\SubmerchantAccount;
use App\Payments\Support\CircuitBreaker;
use App\Support\Tenancy\TenantContext;
use App\Tenancy\Actions\ResolveEnabledGateways;
use App\Tenancy\Actions\ResolveTenantPayoutSchedule;
use App\Tenancy\Actions\ResolveTenantSettlementCurrency;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * POST /v1/submerchant-accounts (stage-08c plan, Endpoints; system-design
 * 7.3). The (tenant_id, gateway) unique constraint is the concurrency
 * guard: the pending row is inserted before the gateway call, so a
 * losing concurrent start never reaches the gateway at all, mirroring
 * InitiatePayment's insert-then-call ordering. Gateway acknowledgment
 * may arrive synchronously (the fake's immediate-approve scenario) or
 * later via webhook (stage-08c plan, Slice 3), so the row created here
 * may already be active by the time it is returned.
 */
final class StartSubmerchantOnboarding
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ResolveEnabledGateways $resolveEnabledGateways,
        private readonly ResolveTenantSettlementCurrency $resolveSettlementCurrency,
        private readonly ResolveTenantPayoutSchedule $resolvePayoutSchedule,
        private readonly GatewayRegistry $gateways,
        private readonly CircuitBreaker $breaker,
        private readonly TransitionSubmerchantAccount $transition,
    ) {}

    public function __invoke(StartSubmerchantOnboardingData $data): SubmerchantAccount
    {
        $tenantId = (string) $this->tenantContext->tenantId();

        $adapter = $this->gateways->get($data->gateway) ?? throw GatewayUnknownException::forGateway($data->gateway);

        if (! in_array($data->gateway, ($this->resolveEnabledGateways)($tenantId), true)) {
            throw GatewayNotEnabledException::forGateway($data->gateway);
        }

        if (! $this->breaker->allowsRequest($data->gateway)) {
            throw GatewayUnavailableException::forGateway($data->gateway);
        }

        try {
            $account = DB::transaction(fn (): SubmerchantAccount => SubmerchantAccount::query()->create([
                'tenant_id' => $tenantId,
                'gateway' => $data->gateway,
                'status' => SubmerchantStatus::Pending,
                'requirements' => [],
            ]));
        } catch (UniqueConstraintViolationException) {
            $existing = SubmerchantAccount::query()
                ->where('tenant_id', $tenantId)
                ->where('gateway', $data->gateway)
                ->firstOrFail();

            throw SubmerchantAlreadyOnboardedException::forExisting($existing->id);
        }

        $registration = new SubmerchantRegistrationRequest(
            tenantId: $tenantId,
            settlementCurrency: ($this->resolveSettlementCurrency)($tenantId),
            payoutSchedule: json_encode(($this->resolvePayoutSchedule)($tenantId) ?? []),
        );

        try {
            $result = $adapter->createSubmerchant($registration);
        } catch (GatewayUnavailableException $e) {
            $this->breaker->recordFailure($data->gateway);

            throw $e;
        }

        $this->breaker->recordSuccess($data->gateway);

        $account->update([
            'gateway_account_reference' => $result->gatewayAccountReference,
            'onboarding_url' => $result->onboardingUrl,
            'requirements' => $result->requirements,
        ]);

        // The gateway may acknowledge synchronously with a status past
        // pending; that move goes through the same guarded conditional
        // transition (and activity log) as the webhook and refresh paths,
        // never a blind status overwrite.
        if ($result->status !== SubmerchantStatus::Pending) {
            ($this->transition)($account->id, $result->status, $result->requirements);
        }

        return SubmerchantAccount::query()->findOrFail($account->id);
    }
}
