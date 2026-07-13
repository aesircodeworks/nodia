<?php

namespace App\Inventory\Actions;

use App\Inventory\Data\EventAvailabilityData;
use App\Inventory\Support\ReadCache;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Date;

/**
 * GET /v1/storefront/events/{event}/availability (stage-10 plan,
 * Endpoints; task breakdown item 10). Fronts GetEventAvailability with
 * the clock-aware Redis read cache: the contract, shape, and codes are
 * unchanged from Stage 6 (GetEventAvailability's own docblock promised
 * exactly this: "a later stage fronts this with a cache without changing
 * the contract"). GetEventAvailability itself still runs, unmodified,
 * every time the cache is missing or stale, so a nonexistent or
 * unpublished event still throws HoldEventNotFoundException before
 * anything is cached (App\Inventory\Support\ReadCache::remember() never
 * caches a thrown exception).
 */
final class GetCachedEventAvailability
{
    private const KIND = 'availability';

    public function __construct(
        private readonly GetEventAvailability $getEventAvailability,
        private readonly TenantContext $tenantContext,
    ) {}

    public function __invoke(string $eventId): EventAvailabilityData
    {
        $payload = ReadCache::remember(
            $this->tenantContext->tenantId(),
            $eventId,
            self::KIND,
            (int) config('onsale.cache.availability_ttl_seconds'),
            Date::now(),
            fn (): array => ($this->getEventAvailability)($eventId)->toArray(),
        );

        return EventAvailabilityData::from($payload);
    }
}
