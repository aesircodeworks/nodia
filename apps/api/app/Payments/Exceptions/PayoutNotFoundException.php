<?php

namespace App\Payments\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised for a payout id that resolves to no visible row: genuinely
 * nonexistent, or a foreign tenant's row that tenant_isolation RLS
 * already hides from a plain find() (stage-08c plan, Endpoints: "404
 * request.not_found"). Deliberately maps to the generic request.not_found
 * code, mirroring App\Payments\Exceptions\SubmerchantAccountNotFoundException.
 */
final class PayoutNotFoundException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $payoutId): self
    {
        return new self("No payout has id [{$payoutId}].");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RequestNotFound;
    }
}
