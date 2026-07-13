<?php

namespace App\Reporting\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by GET /v1/exports/{export}/download while the export is still
 * pending or processing (stage-11 plan, Endpoints: "409 export_not_ready
 * while pending or processing"). New problem code registered in this
 * task per the Stage 1 registry.
 */
final class ExportNotReadyException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $exportId): self
    {
        return new self("Export [{$exportId}] has not completed yet.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ExportNotReady;
    }
}
