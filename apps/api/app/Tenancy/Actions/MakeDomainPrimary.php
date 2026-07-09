<?php

namespace App\Tenancy\Actions;

use App\Tenancy\Data\TenantDomainData;
use App\Tenancy\Exceptions\TenantDomainIsPrimaryException;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class MakeDomainPrimary
{
    /**
     * Demote-then-promote in one transaction: the partial unique index on
     * (tenant_id) WHERE is_primary is the invariant guard, so a concurrent
     * double promotion fails on the index instead of racing through a
     * read-then-write. The promote step is a conditional UPDATE checked by
     * affected-row count: zero rows means the target is already primary,
     * making a replayed request a no-op that writes nothing. The race loser
     * surfaces as a conflict: its demote scan cannot see a primary row
     * committed after the scan's snapshot, so its promote hits the index.
     */
    public function __invoke(TenantDomain $domain): TenantDomainData
    {
        try {
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
        } catch (UniqueConstraintViolationException) {
            throw TenantDomainIsPrimaryException::forConcurrentPromotion($domain->domain);
        }
    }
}
