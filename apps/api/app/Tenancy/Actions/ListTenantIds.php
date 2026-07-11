<?php

namespace App\Tenancy\Actions;

use App\Tenancy\Models\Tenant;
use Illuminate\Support\Collection;

/**
 * A cross-context read for the search:rebuild command (stage-05c plan,
 * task breakdown item 10): iterating every tenant is a Tenancy-owned
 * capability, so it is obtained through this Action rather than
 * EventCatalog querying the tenants table directly (system-design 3.1
 * boundary rule, tests/Architecture/ContextBoundariesTest), mirroring
 * App\Tenancy\Actions\ResolveTenantLocaleSettings's own precedent for the
 * identical crossing.
 */
final class ListTenantIds
{
    /**
     * @return Collection<int, string>
     */
    public function __invoke(): Collection
    {
        return Tenant::query()->pluck('id');
    }
}
