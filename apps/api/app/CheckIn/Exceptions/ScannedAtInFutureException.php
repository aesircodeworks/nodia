<?php

namespace App\CheckIn\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by App\CheckIn\Actions\RecordScan when the request's scanned_at
 * is further ahead of server time than the tolerated clock skew
 * (stage-09 plan, Endpoints "POST /v1/check-ins" errors, and Open
 * questions "Client clock trust"): first-scan-wins trusts device clocks,
 * so a scan claiming to be from the future is rejected outright rather
 * than being allowed to win reconciliation.
 */
final class ScannedAtInFutureException extends RuntimeException implements HasErrorCode
{
    public static function make(): self
    {
        return new self('scanned_at is further in the future than the tolerated clock skew.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ScannedAtInFuture;
    }
}
