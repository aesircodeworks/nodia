<?php

namespace App\Inventory\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by App\Inventory\Actions\JoinQueue when a supplied
 * challenge_response is rejected by the bound
 * App\Inventory\Support\ChallengeVerifier (stage-10 plan, Endpoints
 * "POST /v1/storefront/events/{event}/queue-entries" failure table:
 * "Challenge supplied but rejected by the verifier", code
 * challenge_failed).
 */
final class ChallengeFailedException extends RuntimeException implements HasErrorCode
{
    public static function forEvent(string $eventId): self
    {
        return new self(sprintf('The challenge_response supplied for event "%s" was rejected.', $eventId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ChallengeFailed;
    }
}
