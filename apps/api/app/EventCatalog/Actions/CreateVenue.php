<?php

namespace App\EventCatalog\Actions;

use App\EventCatalog\Data\CreateVenueData;
use App\EventCatalog\Data\VenueData;
use App\EventCatalog\Models\Venue;
use App\Support\Tenancy\TenantContext;

/**
 * Creates a venue in the acting tenant (stage-05a plan, task breakdown
 * item 3). No domain event is recorded: the system-design 9.3 registry
 * has none for venue mutations (stage-05a plan Risks).
 */
final class CreateVenue
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function __invoke(CreateVenueData $data): VenueData
    {
        $venue = Venue::create([
            'tenant_id' => $this->tenantContext->tenantId(),
            'name' => $data->name,
            'address' => $data->address,
            'city' => $data->city,
            'country' => $data->country,
            'capacity' => $data->capacity,
        ]);

        return VenueData::fromModel($venue);
    }
}
