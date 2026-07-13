<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised for a data subject request id that resolves to no visible row:
 * genuinely nonexistent, or a foreign tenant's row that tenant_isolation
 * RLS already hides from a plain find() (stage-12 plan, Endpoints: "404
 * across tenants" for GET /v1/data-subject-requests/{data_subject_
 * request}). Maps to the generic request.not_found code, never a new
 * per-resource one, mirroring App\Reporting\Exceptions\
 * ExportNotFoundException's own precedent.
 */
final class DataSubjectRequestNotFoundException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $dataSubjectRequestId): self
    {
        return new self("No data subject request has id [{$dataSubjectRequestId}].");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RequestNotFound;
    }
}
