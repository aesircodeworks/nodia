<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised when InviteUser's membership insert collides with the
 * memberships (user_id, tenant_id) unique constraint: the invited email
 * already resolves to a user who is already a member of this tenant. The
 * unique index is the invariant guard, never a read-then-write existence
 * check (CLAUDE.md); the database decides who wins a concurrent invite of
 * the same email, and the loser's violation is translated here by
 * constraint name, mirroring App\Identity\Exceptions\RoleNameTakenException's
 * own precedent for the sibling roles (tenant_id, name) constraint.
 */
final class MembershipExistsException extends RuntimeException implements HasErrorCode
{
    public static function for(string $email): self
    {
        return new self(sprintf('"%s" already has a membership in this tenant.', $email));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::MembershipExists;
    }
}
