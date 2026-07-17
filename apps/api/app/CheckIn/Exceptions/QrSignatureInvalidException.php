<?php

namespace App\CheckIn\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by App\CheckIn\Actions\RecordScan when the QR verification
 * Action reports no non-revoked key verifies the payload's HMAC (stage-09
 * plan, Endpoints "POST /v1/check-ins" errors): covers tampering and
 * keys the event never issued, which the payload format makes
 * indistinguishable.
 */
final class QrSignatureInvalidException extends RuntimeException implements HasErrorCode
{
    public static function make(): self
    {
        return new self('The scanned QR payload does not verify against any signing key for this event.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::QrSignatureInvalid;
    }
}
