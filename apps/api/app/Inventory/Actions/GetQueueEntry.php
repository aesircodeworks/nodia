<?php

namespace App\Inventory\Actions;

use App\Inventory\Data\QueueEntryData;
use App\Inventory\Enums\QueueEntryStatus;
use App\Inventory\Exceptions\QueueEntryNotFoundException;
use App\Inventory\Support\AdmissionToken;
use App\Inventory\Support\OnSaleQueue;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Date;

/**
 * GET /v1/storefront/queue-entries/{entry} (stage-10 plan, TDD
 * sequencing Slice 4 and 5; Endpoints), the queue position endpoint the
 * storefront polls. Still waiting is checked first (OnSaleQueue::
 * position()); once the gatekeeper (stage-10 plan task breakdown item 8)
 * has moved the entrant into the admitted sorted set, admission_token
 * and admission_expires_at are signed here, at response time, from that
 * Redis state (the admitted score is the token's own expires_at) rather
 * than ever being persisted (stage-10 plan, Endpoints: "the token is
 * signed at response time from the admitted state, so nothing secret
 * rests in Redis"). An admitted score at or before the caller's own
 * clock is treated exactly like never having joined at all
 * (queue_entry_not_found), independent of whether OnSaleQueue::
 * trimAdmitted()'s own garbage collection has run yet (stage-10 plan
 * Risks "Fake clock versus Redis TTL").
 */
final class GetQueueEntry
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function __invoke(string $entrantId): QueueEntryData
    {
        $tenantId = $this->tenantContext->tenantId();

        $eventId = OnSaleQueue::eventForEntrant($tenantId, $entrantId)
            ?? throw QueueEntryNotFoundException::forId($entrantId);

        $position = OnSaleQueue::position($tenantId, $eventId, $entrantId);

        if ($position !== null) {
            return new QueueEntryData($entrantId, $eventId, QueueEntryStatus::Waiting->value, $position, null, null);
        }

        $now = Date::now();
        $expiresAt = OnSaleQueue::admissionExpiry($tenantId, $eventId, $entrantId);

        if ($expiresAt === null || $now->greaterThanOrEqualTo($expiresAt)) {
            throw QueueEntryNotFoundException::forId($entrantId);
        }

        $token = AdmissionToken::issue($entrantId, $eventId, $tenantId, $expiresAt);

        return new QueueEntryData($entrantId, $eventId, QueueEntryStatus::Admitted->value, null, $token, $expiresAt->toIso8601String());
    }
}
