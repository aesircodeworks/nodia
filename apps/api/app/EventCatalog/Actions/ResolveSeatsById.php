<?php

namespace App\EventCatalog\Actions;

use App\EventCatalog\Data\SeatData;
use App\EventCatalog\Models\Seat;

/**
 * The read-only seam App\Inventory\Actions\GetStorefrontEventSeats and
 * App\Inventory\Http\Controllers\EventSeatController compose seat
 * metadata (section, row, number) through (stage-06 plan, Endpoints "GET
 * /v1/storefront/events/{event}/seats": "Seat metadata ... is composed
 * by calling a Catalog Action returning seat Data objects, never by
 * joining Catalog's tables"). tenant_isolation RLS already scopes the
 * lookup to the acting tenant's own seats; a caller passing a foreign
 * seat_id simply gets no entry back.
 */
final class ResolveSeatsById
{
    /**
     * @param  list<string>  $seatIds
     * @return array<string, SeatData>
     */
    public function __invoke(array $seatIds): array
    {
        if ($seatIds === []) {
            return [];
        }

        $seats = [];

        foreach (Seat::query()->whereIn('id', $seatIds)->get() as $seat) {
            $seats[$seat->id] = SeatData::fromModel($seat);
        }

        return $seats;
    }
}
