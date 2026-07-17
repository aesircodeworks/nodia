<?php

namespace App\CheckIn\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by GET /v1/events/{event}/check-in-manifest when the given
 * event id resolves to no visible row for the acting tenant (stage-09
 * plan, Endpoints "GET /v1/events/{event}/check-in-manifest" errors: 404
 * event_not_found), mirroring App\Orders\Exceptions\
 * SigningKeyEventNotFoundException's own posture: nonexistent and
 * foreign-tenant (via RLS) both render the same code, so existence never
 * leaks.
 */
final class ManifestEventNotFoundException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $eventId): self
    {
        return new self(sprintf('No event has id "%s".', $eventId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::EventNotFound;
    }
}
