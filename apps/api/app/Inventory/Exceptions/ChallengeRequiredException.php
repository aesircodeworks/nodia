<?php

namespace App\Inventory\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by App\Inventory\Actions\JoinQueue when the resolved event's
 * on_sale_policy.challenge_required is set and the request carries no
 * challenge_response (stage-10 plan, Endpoints "POST /v1/storefront/
 * events/{event}/queue-entries" failure table: "Policy requires a
 * challenge, none supplied", code challenge_required). Raised before
 * App\Inventory\Support\ChallengeVerifier is ever consulted.
 */
final class ChallengeRequiredException extends RuntimeException implements HasErrorCode
{
    public static function forEvent(string $eventId): self
    {
        return new self(sprintf('Event "%s" requires a challenge_response to join its waiting room.', $eventId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ChallengeRequired;
    }
}
