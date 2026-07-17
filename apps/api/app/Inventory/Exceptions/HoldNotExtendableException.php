<?php

namespace App\Inventory\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by App\Inventory\Actions\ExtendHold when the extension guard
 * (stage-06 plan, Endpoints; system-design 6.1: `UPDATE holds SET
 * expires_at = :new WHERE id = :id AND status = 'active' AND expires_at
 * > now() AND :new > expires_at`) affects zero rows: the hold is
 * released, expired, committed, or the caller is not actually extending
 * (a `:new` that does not move `expires_at` forward). Extension never
 * resurrects an expired hold and never shortens one.
 */
final class HoldNotExtendableException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $holdId): self
    {
        return new self(sprintf('Hold "%s" cannot be extended.', $holdId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::HoldNotExtendable;
    }
}
