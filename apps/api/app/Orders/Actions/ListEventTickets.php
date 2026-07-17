<?php

namespace App\Orders\Actions;

use App\Orders\Data\TicketManifestEntryData;
use App\Orders\Models\Ticket;
use Illuminate\Support\Collection;

/**
 * The per-event ticket listing seam CheckIn's BuildManifest calls to
 * build the check-in manifest (stage-09 plan, Task 8: "Orders read
 * Actions for CheckIn"). Returns every one of the event's tickets,
 * deterministically ordered by ticket ID, so BuildManifest can overlay
 * check_ins accepted rows and paginate the result without importing
 * App\Orders\Models directly (boundary rule, system-design 3.1).
 */
final class ListEventTickets
{
    /**
     * @return Collection<int, TicketManifestEntryData>
     */
    public function __invoke(string $eventId): Collection
    {
        return Ticket::query()
            ->where('event_id', $eventId)
            ->orderBy('id')
            ->get()
            ->map(TicketManifestEntryData::fromModel(...))
            ->values();
    }
}
