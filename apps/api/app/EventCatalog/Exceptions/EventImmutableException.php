<?php

namespace App\EventCatalog\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by PATCH /v1/events/{event} when the event is canceled
 * (stage-05a plan, endpoint table: "catalog.event_immutable (409, event
 * is canceled)"). Editing a published event is allowed (plan Risks:
 * "Editing published events is allowed"); only canceled is terminal.
 */
final class EventImmutableException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $eventId): self
    {
        return new self(sprintf('Event "%s" is canceled and can no longer be modified.', $eventId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::CatalogEventImmutable;
    }
}
