<?php

namespace App\CheckIn\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by POST /v1/events/{event}/check-in-assignments when the
 * (event_id, user_id) pair already has an assignment row, caught from
 * the unique (tenant_id, event_id, user_id) index on check_in_assignments
 * rather than read-then-write (stage-09 plan, Endpoints "Check-in
 * assignments" errors: 409 already_assigned).
 */
final class AlreadyAssignedException extends RuntimeException implements HasErrorCode
{
    public static function for(string $eventId, string $userId): self
    {
        return new self(sprintf('User "%s" is already assigned to event "%s".', $userId, $eventId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::AlreadyAssigned;
    }
}
