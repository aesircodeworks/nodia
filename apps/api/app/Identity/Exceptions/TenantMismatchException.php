<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised for a customer bearer whose tenant_id claim does not match the
 * tenant this request resolved from the Host header (stage-03 plan,
 * Customer authentication and lifecycle: "a customer token used against
 * another tenant's host is 401 tenant_mismatch"). Distinct from
 * auth.unauthenticated: the token itself may be perfectly valid, just
 * presented against the wrong tenant's storefront.
 */
final class TenantMismatchException extends RuntimeException implements HasErrorCode
{
    public static function make(): self
    {
        return new self('The presented token belongs to a different tenant than the one resolved for this request.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::TenantMismatch;
    }
}
