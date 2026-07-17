<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised when a role delete is refused because at least one membership
 * still references it (stage-03 plan, Roles and memberships endpoint
 * table). Detected by catching the memberships.role_id foreign key
 * violation the database itself raises on the delete, not by a
 * read-then-write existence check (CLAUDE.md: invariant-guarding
 * transitions never read-then-write); Postgres enforces the constraint
 * atomically regardless of concurrent membership creation.
 */
final class RoleInUseException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $roleId): self
    {
        return new self(sprintf('Role "%s" is assigned to at least one membership and cannot be deleted.', $roleId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RoleInUse;
    }
}
