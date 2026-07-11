<?php

namespace App\Inventory\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by App\Inventory\Actions\ReleaseHold for a hold whose status is
 * committed (stage-06 plan, Endpoints: "409 hold_not_releasable when the
 * hold is committed"). A released or expired hold is never routed here:
 * DELETE against either is an idempotent no-op 204 instead.
 */
final class HoldNotReleasableException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $holdId): self
    {
        return new self(sprintf('Hold "%s" is committed and cannot be released.', $holdId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::HoldNotReleasable;
    }
}
