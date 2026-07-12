<?php

namespace App\Inventory\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by App\Inventory\Actions\JoinQueue when the given event_id
 * resolves to a published event whose on_sale_policy.high_demand is
 * false (stage-10 plan, Endpoints "POST /v1/storefront/events/{event}/
 * queue-entries" failure table: "Event not flagged high-demand", code
 * queue_not_active).
 */
final class QueueNotActiveException extends RuntimeException implements HasErrorCode
{
    public static function forEvent(string $eventId): self
    {
        return new self(sprintf('Event "%s" is not flagged high-demand; it has no active waiting room.', $eventId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::QueueNotActive;
    }
}
