<?php

namespace App\Inventory\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by App\Inventory\Actions\CreateHold when the resolved event's
 * on_sale_policy.high_demand is true and the presented X-Admission-Token
 * header fails App\Inventory\Support\AdmissionToken::verify against the
 * event, tenant, and clock (stage-10 plan, Endpoints "POST
 * /v1/storefront/holds" failure table: "Header present but signature,
 * event, tenant, or expiry invalid", code admission_invalid).
 * verify() deliberately does not distinguish which check failed (unknown
 * key id, bad signature, wrong event, wrong tenant, or expired), so
 * every one of those renders this same problem. Checked before any
 * inventory or counter statement runs.
 */
final class AdmissionInvalidException extends RuntimeException implements HasErrorCode
{
    public static function forEvent(string $eventId): self
    {
        return new self(sprintf('The X-Admission-Token presented for event "%s" is not valid.', $eventId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::AdmissionInvalid;
    }
}
