<?php

namespace App\CheckIn\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by App\CheckIn\Actions\ReconcileOfflineScans when a batch names
 * more than 500 scans (stage-09 plan, Endpoints "POST
 * /v1/check-in-batches" errors: "422 for a malformed envelope or more
 * than 500 scans (batch_too_large)"). Checked ahead of any per-scan
 * processing so an oversized batch fails the whole request rather than
 * partially processing it, unlike every other per-scan problem in this
 * endpoint.
 */
final class BatchTooLargeException extends RuntimeException implements HasErrorCode
{
    public static function make(): self
    {
        return new self('A check-in batch may contain at most 500 scans.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::BatchTooLarge;
    }
}
