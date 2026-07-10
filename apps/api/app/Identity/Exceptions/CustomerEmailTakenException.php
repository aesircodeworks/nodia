<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised when RegisterCustomer's insert collides with the customers
 * (tenant_id, email) unique constraint (stage-03 plan, task breakdown
 * item 13). The unique index is the invariant guard, never a
 * read-then-write existence check (CLAUDE.md): the database decides who
 * wins a concurrent create with the same email in the same tenant, and
 * the loser's violation is translated here by constraint name, mirroring
 * App\Identity\Exceptions\RoleNameTakenException's own precedent.
 */
final class CustomerEmailTakenException extends RuntimeException implements HasErrorCode
{
    public static function for(string $email): self
    {
        return new self(sprintf('"%s" is already registered in this tenant.', $email));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::CustomerEmailTaken;
    }
}
