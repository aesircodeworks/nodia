<?php

namespace App\EventCatalog\Actions;

use App\EventCatalog\Data\UpdateVenueData;
use App\EventCatalog\Data\VenueData;
use App\EventCatalog\Models\Venue;
use Spatie\LaravelData\Optional;

/**
 * Updates a venue in the acting tenant (stage-05a plan, task breakdown
 * item 3). Every field is optional; a field absent from the payload is
 * left untouched, mirroring App\Identity\Actions\UpdateRole's precedent.
 * No domain event is recorded, matching CreateVenue.
 */
final class UpdateVenue
{
    public function __invoke(Venue $venue, UpdateVenueData $data): VenueData
    {
        $attributes = [];

        if (! $data->name instanceof Optional) {
            $attributes['name'] = $data->name;
        }

        if (! $data->address instanceof Optional) {
            $attributes['address'] = $data->address;
        }

        if (! $data->city instanceof Optional) {
            $attributes['city'] = $data->city;
        }

        if (! $data->country instanceof Optional) {
            $attributes['country'] = $data->country;
        }

        if (! $data->capacity instanceof Optional) {
            $attributes['capacity'] = $data->capacity;
        }

        $venue->update($attributes);

        return VenueData::fromModel($venue);
    }
}
