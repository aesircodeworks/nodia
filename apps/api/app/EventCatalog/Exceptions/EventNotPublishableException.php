<?php

namespace App\EventCatalog\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by POST /v1/events/{event}/publish when
 * App\EventCatalog\Actions\PublishEvent's conditional UPDATE affects zero
 * rows (stage-05a plan, endpoint table: "catalog.event_not_publishable
 * (409, status was not draft)"): the event was not a draft at the moment
 * the UPDATE ran, whether already published, already canceled, or raced
 * away from draft by a concurrent publish that committed first.
 */
final class EventNotPublishableException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $eventId): self
    {
        return new self(sprintf('Event "%s" is not a draft and cannot be published.', $eventId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::CatalogEventNotPublishable;
    }
}
