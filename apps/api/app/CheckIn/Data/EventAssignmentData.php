<?php

namespace App\CheckIn\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Output of the CheckEventAssignment Action (stage-09 plan, Authorization
 * semantics paragraph): whether the caller may act on the named event,
 * per CheckInAssignmentPolicy's evaluation of checkin.scan plus assignment,
 * with checkin.manage bypassing the assignment requirement.
 */
#[MapName(SnakeCaseMapper::class)]
class EventAssignmentData extends Data
{
    public function __construct(
        public bool $authorized,
    ) {}
}
