<?php

namespace App\Reporting\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised for an export id that resolves to no visible row: genuinely
 * nonexistent, or a foreign tenant's row that tenant_isolation RLS
 * already hides from a plain find() (stage-11 plan, Endpoints: "404
 * (standard) when unknown or cross-tenant, indistinguishable under
 * RLS"). Deliberately maps to the generic request.not_found code rather
 * than a new per-resource one: the task's own registry instructions name
 * only export_not_ready and export_failed as codes to add, mirroring
 * App\Payments\Exceptions\PayoutNotFoundException and
 * SubmerchantAccountNotFoundException's own generic-code precedent.
 */
final class ExportNotFoundException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $exportId): self
    {
        return new self("No export has id [{$exportId}].");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RequestNotFound;
    }
}
