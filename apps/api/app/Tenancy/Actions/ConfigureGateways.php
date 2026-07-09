<?php

namespace App\Tenancy\Actions;

use App\Tenancy\Data\TenantData;
use App\Tenancy\Exceptions\InvalidGatewayConfigurationException;
use App\Tenancy\Models\Tenant;

final class ConfigureGateways
{
    /**
     * Adapter capability validation is deliberately absent: the stored
     * identifiers are checked against real gateway adapters when the
     * payment context exists (stage-02 plan, Scope and non-goals).
     *
     * @param  array<array-key, mixed>  $enabledGateways
     */
    public function __invoke(Tenant $tenant, array $enabledGateways): TenantData
    {
        if (! array_is_list($enabledGateways)) {
            throw InvalidGatewayConfigurationException::notAListOfNonEmptyStrings();
        }

        foreach ($enabledGateways as $gateway) {
            if (! is_string($gateway) || $gateway === '') {
                throw InvalidGatewayConfigurationException::notAListOfNonEmptyStrings();
            }
        }

        $tenant->update(['enabled_gateways' => $enabledGateways]);

        return TenantData::fromModel($tenant);
    }
}
