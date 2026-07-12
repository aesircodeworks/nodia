<?php

namespace App\CheckIn\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by any endpoint gated by App\CheckIn\Actions\CheckEventAssignment
 * (manifest, signing-keys, scan, batch, stage-09 plan Authorization
 * semantics paragraph) when that Action reports the caller unauthorized
 * for the target event: either no checkin.scan or checkin.manage
 * capability at all, or checkin.scan without an assignment row. Both
 * denial reasons render the same code, matching each endpoint's Errors
 * table which lists only checkin_not_assigned for this 403.
 */
final class CheckinNotAssignedException extends RuntimeException implements HasErrorCode
{
    public static function forEvent(string $eventId): self
    {
        return new self(sprintf('The acting user is not authorized for event "%s".', $eventId));
    }

    public static function forScanner(): self
    {
        return new self('The acting user holds no check-in scanning capability.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::CheckinNotAssigned;
    }
}
