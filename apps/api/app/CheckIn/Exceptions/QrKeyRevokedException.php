<?php

namespace App\CheckIn\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by App\CheckIn\Actions\RecordScan when the QR verification
 * Action reports the payload verifies only against a revoked key
 * (stage-09 plan, Endpoints "POST /v1/check-ins" errors): the leak-
 * response signal that a device is still presenting a QR rendered under
 * a key the event owner has explicitly revoked.
 */
final class QrKeyRevokedException extends RuntimeException implements HasErrorCode
{
    public static function make(): self
    {
        return new self('The scanned QR payload verifies only against a revoked signing key.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::QrKeyRevoked;
    }
}
