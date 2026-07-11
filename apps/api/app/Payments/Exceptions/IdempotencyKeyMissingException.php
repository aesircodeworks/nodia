<?php

namespace App\Payments\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

final class IdempotencyKeyMissingException extends RuntimeException implements HasErrorCode
{
    public static function make(): self
    {
        return new self('This endpoint requires an Idempotency-Key header.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::IdempotencyKeyMissing;
    }
}
