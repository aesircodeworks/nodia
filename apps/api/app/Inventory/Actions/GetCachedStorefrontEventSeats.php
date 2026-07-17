<?php

namespace App\Inventory\Actions;

use App\Inventory\Data\StorefrontEventSeatMapData;
use App\Inventory\Support\ReadCache;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Date;

/**
 * GET /v1/storefront/events/{event}/seats (stage-10 plan, Endpoints; task
 * breakdown item 10). Fronts GetStorefrontEventSeats with the clock-aware
 * Redis read cache: the contract, shape, and codes are unchanged from
 * Stage 6. GetStorefrontEventSeats itself still runs, unmodified, every
 * time the cache is missing or stale, so a nonexistent, unpublished, or
 * unseated event still throws its own Stage 6 exceptions before anything
 * is cached (App\Inventory\Support\ReadCache::remember() never caches a
 * thrown exception).
 */
final class GetCachedStorefrontEventSeats
{
    private const KIND = 'seats';

    public function __construct(
        private readonly GetStorefrontEventSeats $getStorefrontEventSeats,
        private readonly TenantContext $tenantContext,
    ) {}

    public function __invoke(string $eventId): StorefrontEventSeatMapData
    {
        $payload = ReadCache::remember(
            $this->tenantContext->tenantId(),
            $eventId,
            self::KIND,
            (int) config('onsale.cache.seats_ttl_seconds'),
            Date::now(),
            fn (): array => ($this->getStorefrontEventSeats)($eventId)->toArray(),
        );

        return StorefrontEventSeatMapData::from($payload);
    }
}
