<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised for a mutation (update or delete) attempted against a global
 * template role (tenant_id NULL, stage-03 plan Data model). Templates are
 * maintained only through the platform's own seeder and are never
 * editable from a tenant's admin surface, even though the roles table's
 * own RLS mutation policies already make a nodia_app write against a
 * template affect zero rows; this is the application-level error a caller
 * actually sees instead of a silent no-op.
 */
final class RoleNotEditableException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $roleId): self
    {
        return new self(sprintf('Role "%s" is a global template and cannot be modified or deleted.', $roleId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RoleNotEditable;
    }
}
