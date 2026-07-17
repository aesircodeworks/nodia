<?php

namespace App\CheckIn\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by App\CheckIn\Actions\RecordScan when the embedded QR rotation
 * counter no longer matches the ticket's current qr_rotation_counter
 * (stage-09 plan, Endpoints "POST /v1/check-ins" errors): the screenshot
 * case, a payload rendered before a later re-render bumped the counter.
 */
final class TicketRotationStaleException extends RuntimeException implements HasErrorCode
{
    public static function make(): self
    {
        return new self('The scanned QR payload was rendered under an earlier ticket rotation.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::TicketRotationStale;
    }
}
