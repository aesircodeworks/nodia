<?php

namespace App\CheckIn\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by App\CheckIn\Actions\RecordScan when the scanned ticket's
 * status is refunded (stage-09 plan, Endpoints "POST /v1/check-ins"
 * errors, code ticket_refunded).
 */
final class CheckInTicketRefundedException extends RuntimeException implements HasErrorCode
{
    public static function make(): self
    {
        return new self('The scanned ticket has been refunded.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::CheckInTicketRefunded;
    }
}
