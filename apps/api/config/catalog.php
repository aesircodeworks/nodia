<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Seat Map Upsert Ceiling
    |--------------------------------------------------------------------------
    |
    | The most seats a single POST/PUT seat-map document may carry, enforced
    | as a validation rule on UpsertSeatMapData.seats so an oversized request
    | is rejected as a problem document before it is fully materialized and
    | chunk-inserted (stage-05b plan, Risks: "chunked bulk inserts ... and a
    | validated payload ceiling"). The default comfortably covers real
    | venues, including large stadiums, while bounding the memory an
    | unbounded payload can consume; a follow-up stage owns a paginated seat
    | write protocol if venues ever exceed it.
    |
    */

    'seat_map_max_seats' => (int) env('SEAT_MAP_MAX_SEATS', 50_000),

];
