<?php

namespace App\Inventory\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by App\Inventory\Actions\CreateHold when the resolved event's
 * on_sale_policy.high_demand is true and the request carries no
 * X-Admission-Token header at all (stage-10 plan, Endpoints "POST
 * /v1/storefront/holds" failure table: "Flagged event, header absent",
 * code admission_required). Checked before any inventory or counter
 * statement runs, ahead of assertHoldable's own per-item checks.
 */
final class AdmissionRequiredException extends RuntimeException implements HasErrorCode
{
    public static function forEvent(string $eventId): self
    {
        return new self(sprintf('Event "%s" is flagged high-demand and requires a valid X-Admission-Token header.', $eventId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::AdmissionRequired;
    }
}
