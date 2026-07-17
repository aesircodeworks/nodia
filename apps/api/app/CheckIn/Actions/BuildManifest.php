<?php

namespace App\CheckIn\Actions;

use App\CheckIn\Data\ManifestEntryData;
use App\CheckIn\Enums\CheckInResult;
use App\CheckIn\Models\CheckIn;
use App\Orders\Actions\ListEventTickets;
use App\Orders\Data\TicketManifestEntryData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the check-in manifest (stage-09 plan, Endpoints "GET
 * /v1/events/{event}/check-in-manifest", Slice 3): calls the Orders
 * per-event ticket-listing Action (Data objects in and out, no Orders
 * model import, per the boundary rule and the Architecture suite that
 * enforces it) and overlays this context's own check_ins accepted rows.
 * A ticket with only duplicate rows shows no checked_in_at, since only
 * the accepted row's scanned_at is ever surfaced. Deterministically
 * ordered by ticket id, matching ListEventTickets's own order, so the
 * controller can cursor-paginate the result without re-sorting.
 *
 * filter[updated_since] narrows on both sides of the overlay: an entry
 * survives when the greater of the ticket's updated_at (Orders-side
 * changes: status, rotation counter) and its accepted check-in's
 * synced_at (check-in state synced from another device) is strictly
 * after the given instant.
 */
final class BuildManifest
{
    public function __construct(
        private readonly ListEventTickets $listEventTickets,
    ) {}

    /**
     * @return Collection<int, ManifestEntryData>
     */
    public function __invoke(string $eventId, ?string $updatedSince = null): Collection
    {
        $tickets = ($this->listEventTickets)($eventId);

        $acceptedByTicketId = CheckIn::query()
            ->where('event_id', $eventId)
            ->where('result', CheckInResult::Accepted)
            ->get(['ticket_id', 'scanned_at', 'synced_at'])
            ->keyBy('ticket_id');

        $since = $updatedSince === null ? null : CarbonImmutable::parse($updatedSince);

        return $tickets
            ->filter(function (TicketManifestEntryData $ticket) use ($since, $acceptedByTicketId): bool {
                if ($since === null) {
                    return true;
                }

                $ticketUpdatedAt = CarbonImmutable::parse($ticket->updatedAt);
                $accepted = $acceptedByTicketId->get($ticket->ticketId);
                $syncedAt = $accepted === null ? null : CarbonImmutable::instance($accepted->synced_at);

                $latest = $syncedAt !== null && $syncedAt->greaterThan($ticketUpdatedAt)
                    ? $syncedAt
                    : $ticketUpdatedAt;

                return $latest->greaterThan($since);
            })
            ->map(function (TicketManifestEntryData $ticket) use ($acceptedByTicketId): ManifestEntryData {
                $accepted = $acceptedByTicketId->get($ticket->ticketId);

                return new ManifestEntryData(
                    $ticket->ticketId,
                    $ticket->status,
                    $ticket->rotationCounter,
                    $accepted === null ? null : $this->format($accepted->scanned_at),
                );
            })
            ->values();
    }

    private function format(mixed $timestamp): string
    {
        return CarbonImmutable::instance($timestamp)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
