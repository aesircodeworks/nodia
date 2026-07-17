<?php

namespace App\Inventory\Actions;

use App\EventCatalog\Actions\ResolveEventForHold;
use App\Inventory\Data\JoinQueueData;
use App\Inventory\Data\QueueEntryData;
use App\Inventory\Enums\QueueEntryStatus;
use App\Inventory\Exceptions\ChallengeFailedException;
use App\Inventory\Exceptions\ChallengeRequiredException;
use App\Inventory\Exceptions\HoldEventNotFoundException;
use App\Inventory\Exceptions\QueueNotActiveException;
use App\Inventory\Support\ChallengeVerifier;
use App\Inventory\Support\OnSaleQueue;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Spatie\LaravelData\Optional;

/**
 * POST /v1/storefront/events/{event}/queue-entries (stage-10 plan, TDD
 * sequencing Slice 4; Endpoints). Redis-only: nothing here opens a
 * database transaction or records an outbox event (stage-10 plan,
 * Domain events: "there is no database transaction on the queue path,
 * so there is legitimately nothing to record"). Reuses
 * App\EventCatalog\Actions\ResolveEventForHold, the same read-only seam
 * App\Inventory\Actions\CreateHold already depends on, so Inventory
 * never touches App\EventCatalog\Models\Event directly (system-design
 * 3.1 boundary rule): a nonexistent or unpublished event id renders the
 * same event_not_found code either way.
 *
 * Immediate admission on an empty queue with budget available (the
 * Endpoints table's own "the join may admit immediately" branch) is
 * gatekeeper machinery (stage-10 plan task breakdown item 8, not yet
 * built): every join in this task lands waiting.
 */
final class JoinQueue
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ResolveEventForHold $resolveEvent,
        private readonly ChallengeVerifier $challengeVerifier,
    ) {}

    public function __invoke(string $eventId, JoinQueueData $data): QueueEntryData
    {
        $event = ($this->resolveEvent)($eventId) ?? throw HoldEventNotFoundException::forId($eventId);

        if (! $event->onSalePolicy->highDemand) {
            throw QueueNotActiveException::forEvent($eventId);
        }

        if ($event->onSalePolicy->challengeRequired) {
            $this->assertChallengePassed($eventId, $data);
        }

        $tenantId = $this->tenantContext->tenantId();
        $entrantId = Str::uuid7()->toString();

        $position = OnSaleQueue::join($tenantId, $eventId, $entrantId, Date::now());

        return new QueueEntryData($entrantId, $eventId, QueueEntryStatus::Waiting->value, $position, null, null);
    }

    private function assertChallengePassed(string $eventId, JoinQueueData $data): void
    {
        $response = $data->challengeResponse instanceof Optional ? null : $data->challengeResponse;

        if ($response === null || $response === '') {
            throw ChallengeRequiredException::forEvent($eventId);
        }

        if (! $this->challengeVerifier->verify($response)) {
            throw ChallengeFailedException::forEvent($eventId);
        }
    }
}
