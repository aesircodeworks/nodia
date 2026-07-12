<?php

namespace App\CheckIn\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by POST /v1/events/{event}/check-in-assignments when the given
 * user_id has no membership in the acting tenant (stage-09 plan,
 * Endpoints "Check-in assignments" errors: 422 user_not_member).
 */
final class UserNotMemberException extends RuntimeException implements HasErrorCode
{
    public static function forUser(string $userId): self
    {
        return new self(sprintf('User "%s" has no membership in the acting tenant.', $userId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::UserNotMember;
    }
}
