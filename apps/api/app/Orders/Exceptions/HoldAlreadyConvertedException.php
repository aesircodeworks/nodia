<?php

namespace App\Orders\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * The unique index on orders.hold_id makes double conversion
 * structurally impossible (stage-07 plan, Data model "orders"); this is
 * its wire face.
 */
final class HoldAlreadyConvertedException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $holdId): self
    {
        return new self(sprintf('An order already references hold "%s".', $holdId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::HoldAlreadyConverted;
    }
}
