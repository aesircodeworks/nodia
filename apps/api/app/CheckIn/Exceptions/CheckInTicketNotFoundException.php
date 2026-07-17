<?php

namespace App\CheckIn\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by App\CheckIn\Actions\RecordScan when the QR verification
 * Action reports a verified signature naming a ticket that does not
 * exist for the acting tenant (stage-09 plan, Endpoints "POST
 * /v1/check-ins" errors, code ticket_not_found). RLS makes a
 * cross-tenant ticket id indistinguishable from a nonexistent one.
 */
final class CheckInTicketNotFoundException extends RuntimeException implements HasErrorCode
{
    public static function make(): self
    {
        return new self('No ticket resolves to the scanned QR payload for this tenant.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::CheckInTicketNotFound;
    }
}
