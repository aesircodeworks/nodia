<?php

namespace App\Tenancy\Actions;

use App\Tenancy\Data\TenantLocaleSettingsData;
use App\Tenancy\Models\Tenant;

/**
 * A cross-context read for EventCatalog's translatable-content validation
 * (stage-05a plan, task breakdown item 5, Feature: "translatable payloads
 * require the tenant default locale and reject unsupported locales"),
 * mirroring App\Tenancy\Actions\ResolveTenantDefaultLocale's own precedent
 * for the identical crossing (contexts never reach into another
 * context's Models directly, tests/Architecture/ContextBoundariesTest).
 */
final class ResolveTenantLocaleSettings
{
    public function __invoke(string $tenantId): TenantLocaleSettingsData
    {
        $tenant = Tenant::query()->findOrFail($tenantId);

        return new TenantLocaleSettingsData($tenant->default_locale, $tenant->supported_locales);
    }
}
