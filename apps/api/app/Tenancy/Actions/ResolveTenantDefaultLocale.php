<?php

namespace App\Tenancy\Actions;

use App\Tenancy\Models\Tenant;

/**
 * A minimal cross-context read for Identity's RegisterCustomer (stage-03
 * plan, task breakdown item 13: "locale falls back to tenant default",
 * system-design 12). Contexts never reach into another context's Models
 * directly (tests/Architecture/ContextBoundariesTest), so this one-line
 * lookup is exposed as an Action instead of Identity importing
 * App\Tenancy\Models\Tenant directly, mirroring the reverse crossing
 * App\Identity\Actions\ResolveTenantAccess already established for
 * Tenancy's own ResolveTenantFromHeader.
 */
final class ResolveTenantDefaultLocale
{
    public function __invoke(string $tenantId): string
    {
        return Tenant::query()->findOrFail($tenantId)->default_locale;
    }
}
