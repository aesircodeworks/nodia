<?php

namespace App\Inventory\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by App\Inventory\Actions\CommitHold when the commit guard
 * (stage-06 plan, TDD sequencing Slice 4: "refuses an expired-but-unswept
 * hold ... refuses double commit, refuses a released hold") affects zero
 * rows, either on the hold's own active -> committed transition or on one
 * of its items' held -> sold counter transitions. An expired hold the
 * sweeper has not yet swept is refused here too: the guard checks
 * `expires_at > now()` in the same conditional UPDATE, so conversion-time
 * validation covers the gap even when the sweeper lags (system-design
 * 13).
 */
final class HoldNotCommittableException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $holdId): self
    {
        return new self(sprintf('Hold "%s" cannot be committed.', $holdId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::HoldNotCommittable;
    }
}
