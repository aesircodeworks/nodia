<?php

namespace App\EventCatalog\Actions;

use App\EventCatalog\Data\SeatInputData;
use App\EventCatalog\Data\SeatMapData;
use App\EventCatalog\Data\UpsertSeatMapData;
use App\EventCatalog\Exceptions\SeatMapDuplicateSeatsException;
use App\EventCatalog\Exceptions\SeatMapNameTakenException;
use App\EventCatalog\Models\Seat;
use App\EventCatalog\Models\SeatMap;
use App\EventCatalog\Models\Venue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The seat map plus its seats as one document (system-design 3.2,
 * stage-05b plan, TDD sequencing Slice 2: create path only; task-04 adds
 * a full-replace entry point for PUT /v1/seat-maps/{seat_map} to this
 * same class). create() is called from the tenant transaction the
 * request middleware already opened (App\Support\Tenancy\
 * TenantTransaction::asTenant, mirroring every other admin Action), so
 * DB::transaction() below opens a savepoint, not a fresh top-level
 * transaction; unit tests calling this directly get the same guarantee
 * because they run inside their own asTenant() call.
 */
final class UpsertSeatMap
{
    /**
     * Bulk-inserted per chunk rather than one insert statement covering an
     * entire arena-sized payload (stage-05b plan, Risks: "Large
     * templates ... chunked bulk inserts inside the transaction").
     */
    private const int INSERT_CHUNK_SIZE = 500;

    public function create(Venue $venue, UpsertSeatMapData $data): SeatMapData
    {
        $this->assertNoDuplicateSeats($data->seats);

        return DB::transaction(function () use ($venue, $data): SeatMapData {
            $seatMap = $this->createSeatMap($venue, $data);

            $this->insertSeats($seatMap, $data->seats);

            $seatMap->setRelation('seats', $seatMap->seats()->get());

            return SeatMapData::fromModel($seatMap);
        });
    }

    private function createSeatMap(Venue $venue, UpsertSeatMapData $data): SeatMap
    {
        try {
            return SeatMap::create([
                'tenant_id' => $venue->tenant_id,
                'venue_id' => $venue->id,
                'name' => $data->name,
                'layout' => $data->layout,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'seat_maps_venue_id_name_unique')) {
                throw SeatMapNameTakenException::for($venue->id, $data->name);
            }

            throw $e;
        }
    }

    /**
     * @param  list<SeatInputData>  $seats
     */
    private function insertSeats(SeatMap $seatMap, array $seats): void
    {
        if ($seats === []) {
            return;
        }

        $now = Date::now();

        $rows = array_map(fn (SeatInputData $seat): array => [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $seatMap->tenant_id,
            'seat_map_id' => $seatMap->id,
            'section' => $seat->section,
            'row' => $seat->row,
            'number' => $seat->number,
            'position_x' => $seat->positionX,
            'position_y' => $seat->positionY,
            'created_at' => $now,
            'updated_at' => $now,
        ], $seats);

        foreach (array_chunk($rows, self::INSERT_CHUNK_SIZE) as $chunk) {
            Seat::query()->insert($chunk);
        }
    }

    /**
     * Rejected before any query runs (stage-05b plan, TDD sequencing
     * Slice 2), independent of the seat_maps_venue_id_name_unique
     * database guard below, which only ever fires for the seat map's own
     * name: the seats natural key's own uniqueness within one payload has
     * no database round trip to lean on until the rows are inserted, by
     * which point a duplicate would already be a wasted write.
     *
     * @param  list<SeatInputData>  $seats
     */
    private function assertNoDuplicateSeats(array $seats): void
    {
        $positionsByKey = [];

        foreach ($seats as $position => $seat) {
            $key = $seat->section."\0".$seat->row."\0".$seat->number;
            $positionsByKey[$key][] = $position;
        }

        $offending = [];

        foreach ($positionsByKey as $positions) {
            if (count($positions) > 1) {
                array_push($offending, ...$positions);
            }
        }

        if ($offending !== []) {
            sort($offending);

            throw SeatMapDuplicateSeatsException::forPositions($offending);
        }
    }
}
