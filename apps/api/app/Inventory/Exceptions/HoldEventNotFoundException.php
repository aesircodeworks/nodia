<?php

namespace App\Inventory\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by App\Inventory\Actions\CreateHold when the given event_id
 * resolves to no published event visible to the resolved tenant
 * (stage-06 plan, Endpoints "POST /v1/storefront/holds" failure table:
 * "Event not published or not found for tenant", code event_not_found).
 * Nonexistent and unpublished both render the same code, so unpublished
 * existence never leaks, mirroring the storefront event read's own
 * not-found posture.
 */
final class HoldEventNotFoundException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $eventId): self
    {
        return new self(sprintf('No published event has id "%s".', $eventId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::EventNotFound;
    }
}
