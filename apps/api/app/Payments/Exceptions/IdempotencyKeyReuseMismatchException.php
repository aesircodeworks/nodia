<?php

namespace App\Payments\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

final class IdempotencyKeyReuseMismatchException extends RuntimeException implements HasErrorCode
{
    public static function make(): self
    {
        return new self('This Idempotency-Key was already used with a different request payload.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::IdempotencyKeyReuseMismatch;
    }
}
