<?php

namespace App\Orders\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by the signing-key endpoints when the given event id resolves
 * to no visible row for the acting tenant (stage-09 plan, Endpoints
 * "GET/POST /v1/events/{event}/signing-keys" errors: 404
 * event_not_found), mirroring App\Inventory\Exceptions\
 * HoldEventNotFoundException's own posture: nonexistent and
 * foreign-tenant (via RLS) both render the same code, so existence never
 * leaks.
 */
final class SigningKeyEventNotFoundException extends RuntimeException implements HasErrorCode
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
