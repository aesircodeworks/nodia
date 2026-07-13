<?php

namespace App\Reporting\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by GET /v1/exports/{export}/download for a failed export
 * (stage-11 plan, Endpoints: "409 export_failed when failed"). New
 * problem code registered in this task per the Stage 1 registry.
 */
final class ExportFailedException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $exportId): self
    {
        return new self("Export [{$exportId}] failed and has no file to download.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ExportFailed;
    }
}
