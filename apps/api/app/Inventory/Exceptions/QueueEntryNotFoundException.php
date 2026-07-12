<?php

namespace App\Inventory\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by App\Inventory\Actions\GetQueueEntry when the given entrant
 * id has no App\Inventory\Support\OnSaleQueue reverse-lookup entry for
 * the resolved tenant: unknown, expired (its Redis key's TTL already
 * elapsed), or created under a different tenant, all rendering the same
 * problem so entrant existence never leaks across tenants (stage-10
 * plan, Endpoints "GET /v1/storefront/queue-entries/{entry}": "cross-
 * tenant resolves to not-found by key namespacing, mirroring the
 * RLS-driven 404 pattern").
 */
final class QueueEntryNotFoundException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $entrantId): self
    {
        return new self(sprintf('No queue entry has id "%s" for the resolved tenant.', $entrantId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::QueueEntryNotFound;
    }
}
