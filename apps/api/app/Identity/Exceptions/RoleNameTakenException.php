<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised when a role create or rename collides with the roles
 * (tenant_id, name) unique constraint. The unique index is the invariant
 * guard, never a read-then-write existence check (CLAUDE.md); the
 * database decides who wins a concurrent create, and the loser's
 * violation is translated here by constraint name, mirroring
 * App\Tenancy\Actions\RegisterDomain's own precedent for
 * DomainAlreadyRegisteredException.
 */
final class RoleNameTakenException extends RuntimeException implements HasErrorCode
{
    public static function for(string $name): self
    {
        return new self(sprintf('A role named "%s" already exists in this tenant.', $name));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RoleNameTaken;
    }
}
