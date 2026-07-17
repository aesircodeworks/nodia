<?php

namespace App\Inventory\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One operation inside an UpdateEventSeatsData request (stage-06 plan,
 * Endpoints "PATCH /v1/events/{event}/seats": "operations as
 * [{event_seat_id, op}] where op is block, unblock, or
 * {assign_ticket_type: uuid|null}"). This class resolves that
 * polymorphic op shape as two flat fields, `op` (a string enum) plus
 * `ticket_type_id` (used only when op is assign_ticket_type, ignored
 * otherwise, null meaning "unzone this seat"), the same deliberate,
 * self-authored resolution CreateHoldData's own docblock precedent
 * establishes for an ambiguous endpoint-table shape: a nested
 * discriminated-union JSON value has no direct laravel-data
 * representation, and no prior stage-06 task committed to a different
 * flattening.
 */
#[MapName(SnakeCaseMapper::class)]
class UpdateEventSeatOperationData extends Data
{
    public function __construct(
        public string $eventSeatId,
        public string $op,
        public ?string $ticketTypeId = null,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'event_seat_id' => ['required', 'uuid'],
            'op' => ['required', 'string', 'in:block,unblock,assign_ticket_type'],
            'ticket_type_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
