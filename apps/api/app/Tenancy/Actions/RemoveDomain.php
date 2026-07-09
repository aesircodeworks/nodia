<?php

namespace App\Tenancy\Actions;

use App\Tenancy\Exceptions\TenantDomainIsPrimaryException;
use App\Tenancy\Models\TenantDomain;

final class RemoveDomain
{
    /**
     * Conditional DELETE checked by affected-row count, so a domain
     * promoted to primary between read and delete is refused instead of
     * removed (a tenant must never lose its primary domain to a race).
     */
    public function __invoke(TenantDomain $domain): void
    {
        $deleted = TenantDomain::query()
            ->whereKey($domain->id)
            ->where('is_primary', false)
            ->delete();

        if ($deleted === 0) {
            throw TenantDomainIsPrimaryException::for($domain->domain);
        }
    }
}
