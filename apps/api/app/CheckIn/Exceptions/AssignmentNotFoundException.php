<?php

namespace App\CheckIn\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by DELETE /v1/check-in-assignments/{assignment} when the given
 * id resolves to no visible row for the acting tenant: genuinely
 * nonexistent, or a foreign tenant's row RLS already hides, both render
 * the same code so existence never leaks (stage-09 plan, Endpoints
 * "Check-in assignments" errors: 404 assignment_not_found).
 */
final class AssignmentNotFoundException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $assignmentId): self
    {
        return new self(sprintf('No check-in assignment has id "%s".', $assignmentId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::AssignmentNotFound;
    }
}
