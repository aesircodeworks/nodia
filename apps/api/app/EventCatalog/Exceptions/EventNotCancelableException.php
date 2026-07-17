<?php

namespace App\EventCatalog\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by POST /v1/events/{event}/cancel when
 * App\EventCatalog\Actions\CancelEvent's conditional UPDATE affects zero
 * rows (stage-05a plan, endpoint table: "catalog.event_not_cancelable
 * (409, already canceled)"): the event was already canceled at the
 * moment the UPDATE ran. Cancel is legal from both draft and published,
 * so this is the only source status that ever rejects it.
 */
final class EventNotCancelableException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $eventId): self
    {
        return new self(sprintf('Event "%s" is already canceled.', $eventId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::CatalogEventNotCancelable;
    }
}
