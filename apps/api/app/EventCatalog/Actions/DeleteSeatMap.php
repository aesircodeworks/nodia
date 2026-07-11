<?php

namespace App\EventCatalog\Actions;

use App\EventCatalog\Exceptions\SeatMapInUseException;
use App\EventCatalog\Models\SeatMap;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a seat map template; its seats cascade at the database level
 * (stage-05b plan, Deletion semantics, TDD sequencing Slice 5). Mirrors
 * App\Identity\Actions\DeleteRole's own precedent: events.seat_map_id
 * carries a foreign key with no cascade (the additive stage-05b task-05
 * migration), so Postgres itself refuses the delete while at least one
 * event still references the template; the constraint is the invariant
 * guard, never a read-then-write existence check (CLAUDE.md), and is
 * enforced atomically regardless of concurrent event linkage. No
 * Laravel-native subclass of QueryException exists for a foreign key
 * violation the way UniqueConstraintViolationException does for a unique
 * violation, so the SQLSTATE is checked directly, mirroring
 * DeleteRole's own '23503' (foreign_key_violation) check. Deletes
 * through the DB query builder rather than $seatMap->delete(): Larastan
 * can fully resolve Eloquent Model::delete()'s own signature, which
 * declares no thrown QueryException, and reports this catch as dead code
 * for it, but cannot for the facade-dispatched call, so the query
 * builder form is both correct and the one Larastan accepts without a
 * suppression comment.
 *
 * Stage 6 (Slice 5, task breakdown item 9) extends the same mapping to
 * a template whose seats are materialized into at least one event's
 * event_seats: seats cascade on seat_maps delete, but
 * event_seats.seat_id restricts on delete, so the cascade itself fails
 * with the event_seats_seat_id_foreign violation, caught here
 * alongside the direct events_seat_map_id_foreign case.
 */
final class DeleteSeatMap
{
    private const RESTRICTING_CONSTRAINTS = [
        'events_seat_map_id_foreign',
        'event_seats_seat_id_foreign',
    ];

    public function __invoke(SeatMap $seatMap): void
    {
        try {
            DB::table('seat_maps')->where('id', $seatMap->id)->delete();
        } catch (QueryException $e) {
            if ($e->getCode() === '23503' && $this->restrictedByMaterialization($e->getMessage())) {
                throw SeatMapInUseException::forId($seatMap->id);
            }

            throw $e;
        }
    }

    private function restrictedByMaterialization(string $message): bool
    {
        foreach (self::RESTRICTING_CONSTRAINTS as $constraint) {
            if (str_contains($message, $constraint)) {
                return true;
            }
        }

        return false;
    }
}
