<?php

namespace App\Tenancy\Actions;

use App\Tenancy\Data\TenantDomainData;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\DB;

final class MakeDomainPrimary
{
    /**
     * Demote-then-promote in one transaction: the partial unique index on
     * (tenant_id) WHERE is_primary is the invariant guard, so a concurrent
     * double promotion fails on the index instead of racing through a
     * read-then-write. The promote step is a conditional UPDATE checked by
     * affected-row count: zero rows means the target is already primary,
     * making a replayed request a no-op that writes nothing.
     */
    public function __invoke(TenantDomain $domain): TenantDomainData
    {
        return DB::transaction(function () use ($domain): TenantDomainData {
            TenantDomain::query()
                ->where('tenant_id', $domain->tenant_id)
                ->where('is_primary', true)
                ->whereKeyNot($domain->id)
                ->update(['is_primary' => false]);

            TenantDomain::query()
                ->whereKey($domain->id)
                ->where('is_primary', false)
                ->update(['is_primary' => true]);

            // refresh() throws ModelNotFoundException when the target row
            // vanished, rolling the demotion back with the transaction.
            return TenantDomainData::fromModel($domain->refresh());
        });
    }
}
