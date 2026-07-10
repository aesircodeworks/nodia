<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised when a role change or removal would leave a tenant with no
 * membership holding the global Owner template role (stage-03 plan,
 * Roles and memberships endpoint table: "last_owner_removal if demoting
 * the only Owner"). Guarded by App\Identity\Support\GuardsLastOwner,
 * shared by App\Identity\Actions\AssignRole and RemoveMembership.
 */
final class LastOwnerRemovalException extends RuntimeException implements HasErrorCode
{
    public static function forMembership(string $membershipId): self
    {
        return new self(sprintf('Membership "%s" is the tenant\'s only Owner and cannot be demoted or removed.', $membershipId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::LastOwnerRemoval;
    }
}
