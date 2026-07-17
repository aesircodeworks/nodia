<?php

namespace App\Orders\Exceptions;

use App\Orders\Enums\OrderStatus;
use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * A transition Action's conditional UPDATE affected zero rows on an
 * order that exists: the order is not in the state the arc leaves from
 * (stage-07 plan, Error codes; system-design 7.1).
 */
final class InvalidOrderTransitionException extends RuntimeException implements HasErrorCode
{
    public static function toStatus(string $orderId, OrderStatus $to): self
    {
        return new self(sprintf('Order "%s" cannot transition to %s from its current state.', $orderId, $to->value));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::InvalidOrderTransition;
    }
}
