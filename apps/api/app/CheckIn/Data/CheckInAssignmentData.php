<?php

namespace App\CheckIn\Data;

use App\CheckIn\Models\CheckInAssignment;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * GET/POST /v1/events/{event}/check-in-assignments response shape
 * (stage-09 plan, Endpoints "Check-in assignments").
 */
#[MapName(SnakeCaseMapper::class)]
class CheckInAssignmentData extends Data
{
    public function __construct(
        public string $id,
        public string $eventId,
        public string $userId,
        public string $createdAt,
    ) {}

    public static function fromModel(CheckInAssignment $assignment): self
    {
        return new self(
            $assignment->id,
            $assignment->event_id,
            $assignment->user_id,
            $assignment->created_at->toJSON(),
        );
    }
}
