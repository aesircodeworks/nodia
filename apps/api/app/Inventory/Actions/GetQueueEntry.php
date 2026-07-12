<?php

namespace App\Inventory\Actions;

use App\Inventory\Data\QueueEntryData;
use App\Inventory\Enums\QueueEntryStatus;
use App\Inventory\Exceptions\QueueEntryNotFoundException;
use App\Inventory\Support\OnSaleQueue;
use App\Support\Tenancy\TenantContext;

/**
 * GET /v1/storefront/queue-entries/{entry} (stage-10 plan, TDD
 * sequencing Slice 4; Endpoints), the queue position endpoint the
 * storefront polls. admission_token and admission_expires_at stay null
 * until the gatekeeper (stage-10 plan task breakdown item 8, not built
 * by this task) admits the entrant; every entrant this task can ever
 * find is waiting.
 */
final class GetQueueEntry
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function __invoke(string $entrantId): QueueEntryData
    {
        $tenantId = $this->tenantContext->tenantId();

        $eventId = OnSaleQueue::eventForEntrant($tenantId, $entrantId)
            ?? throw QueueEntryNotFoundException::forId($entrantId);

        $position = OnSaleQueue::position($tenantId, $eventId, $entrantId)
            ?? throw QueueEntryNotFoundException::forId($entrantId);

        return new QueueEntryData($entrantId, $eventId, QueueEntryStatus::Waiting->value, $position, null, null);
    }
}
