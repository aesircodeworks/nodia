<?php

namespace App\EventCatalog\Actions;

use App\EventCatalog\Data\SeatInputData;
use App\EventCatalog\Data\SeatMapData;
use App\EventCatalog\Data\UpsertSeatMapData;
use App\EventCatalog\Exceptions\SeatMapConflictException;
use App\EventCatalog\Exceptions\SeatMapDuplicateSeatsException;
use App\EventCatalog\Exceptions\SeatMapNameTakenException;
use App\EventCatalog\Exceptions\SeatMapNotFoundException;
use App\EventCatalog\Models\Seat;
use App\EventCatalog\Models\SeatMap;
use App\EventCatalog\Models\Venue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The seat map plus its seats as one document (system-design 3.2,
 * stage-05b plan, TDD sequencing Slices 2 and 4: create() is the create
 * path, replace() is the full-replace entry point for PUT
 * /v1/seat-maps/{seat_map}). Both are called from the tenant transaction
 * the request middleware already opened (App\Support\Tenancy\
 * TenantTransaction::asTenant, mirroring every other admin Action), so
 * DB::transaction() below opens a savepoint, not a fresh top-level
 * transaction; unit tests calling either method directly get the same
 * guarantee because they run inside their own asTenant() call.
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

    /**
     * Full replace: name, layout, and the seat set (stage-05b plan,
     * Endpoints: PUT /v1/seat-maps/{seat_map}). The seat_maps row is
     * locked for the duration of the transaction (SELECT ... FOR UPDATE)
     * before anything else runs, so two concurrent replaces of the same
     * map serialize into a strict before/after order instead of
     * interleaving their diffs; the second caller's transaction blocks on
     * the lock until the first commits, then reads the first's already-
     * committed state as its own starting point. Seats are diffed against
     * that locked read by natural key (section, row, number): a matched
     * seat keeps its id and only its coordinates change, an unmatched
     * incoming seat is a fresh insert, and an existing seat missing from
     * the payload is deleted. Renaming a seat's natural key is therefore
     * indistinguishable from deleting the old seat and inserting a new
     * one under a new id (stage-05b plan, Risks: "Seat identity across
     * natural-key edits").
     */
    public function replace(SeatMap $seatMap, UpsertSeatMapData $data): SeatMapData
    {
        $this->assertNoDuplicateSeats($data->seats);

        return DB::transaction(function () use ($seatMap, $data): SeatMapData {
            $seatMap = SeatMap::query()->whereKey($seatMap->getKey())->lockForUpdate()->first()
                ?? throw SeatMapNotFoundException::forId((string) $seatMap->getKey());

            $this->replaceSeats($seatMap, $data->seats);
            $this->updateSeatMap($seatMap, $data);

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
     * name and layout are replaced outright (stage-05b plan, Endpoints:
     * "name and layout are replaced"), mirroring createSeatMap()'s own
     * seat_maps_venue_id_name_unique translation for a rename that
     * collides with another template on the same venue.
     */
    private function updateSeatMap(SeatMap $seatMap, UpsertSeatMapData $data): void
    {
        try {
            $seatMap->update([
                'name' => $data->name,
                'layout' => $data->layout,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'seat_maps_venue_id_name_unique')) {
                throw SeatMapNameTakenException::for($seatMap->venue_id, $data->name);
            }

            throw $e;
        }
    }

    /**
     * Diffs the locked read of the map's current seats against the
     * incoming payload by natural key: matched (intersection) seats keep
     * their id and get their coordinates updated, existing-only seats are
     * deleted, incoming-only seats are inserted. The insert set is always
     * disjoint from every row still present after the delete: it is
     * defined as the incoming keys absent from the pre-delete existing
     * set, so it can never collide with a matched (kept) row (stage-05b
     * plan, Endpoints: the residual-unique-violation note this fact is
     * what makes "unreachable once writers serialize on the lock").
     *
     * @param  list<SeatInputData>  $seats
     */
    private function replaceSeats(SeatMap $seatMap, array $seats): void
    {
        // toBase(): Illuminate\Database\Eloquent\Collection overrides
        // except()/only() to filter by the models' own primary key rather
        // than the collection's array keys, which would silently defeat
        // keyBy()'s natural-key indexing below (every existing seat would
        // read as "stale" since no natural-key string is ever a real
        // seat id); toBase() drops back to the plain Illuminate\Support\
        // Collection, where except()/only() operate on the array keys as
        // intended.
        $existingByKey = $seatMap->seats()->get()->keyBy(
            fn (Seat $seat): string => $this->naturalKey($seat->section, $seat->row, $seat->number),
        )->toBase();

        /** @var Collection<string, SeatInputData> $incomingByKey */
        $incomingByKey = collect($seats)->keyBy(
            fn (SeatInputData $seat): string => $this->naturalKey($seat->section, $seat->row, $seat->number),
        );

        $staleIds = $existingByKey->except($incomingByKey->keys()->all())->pluck('id')->all();

        if ($staleIds !== []) {
            Seat::query()->whereIn('id', $staleIds)->delete();
        }

        $now = Date::now();

        foreach ($incomingByKey as $key => $seatInput) {
            $existing = $existingByKey->get($key);

            if ($existing === null) {
                continue;
            }

            $existing->forceFill([
                'position_x' => $seatInput->positionX,
                'position_y' => $seatInput->positionY,
                'updated_at' => $now,
            ])->save();
        }

        $newSeats = $incomingByKey->except($existingByKey->keys()->all())->values()->all();

        $this->insertSeats($seatMap, $newSeats);
    }

    private function naturalKey(string $section, string $row, string $number): string
    {
        return $section."\0".$row."\0".$number;
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

        try {
            foreach (array_chunk($rows, self::INSERT_CHUNK_SIZE) as $chunk) {
                Seat::query()->insert($chunk);
            }
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'seats_seat_map_id_section_row_number_unique')) {
                throw SeatMapConflictException::for($seatMap->id);
            }

            throw $e;
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
            $key = $this->naturalKey($seat->section, $seat->row, $seat->number);
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
