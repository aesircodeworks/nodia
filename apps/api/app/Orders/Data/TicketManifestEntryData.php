<?php

namespace App\Orders\Data;

use App\Orders\Models\Ticket;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One ticket in ListEventTickets's output (stage-09 plan, Task 8: "Orders
 * read Actions for CheckIn"), the raw material CheckIn's BuildManifest
 * overlays with check_ins accepted rows. updated_at is carried so
 * BuildManifest can compare it against filter[updated_since] on the
 * Orders side of the manifest delta; it is not part of the manifest wire
 * response itself (ManifestEntryData drops it in favor of
 * checked_in_at).
 */
#[MapName(SnakeCaseMapper::class)]
class TicketManifestEntryData extends Data
{
    public function __construct(
        public string $ticketId,
        public string $status,
        public int $rotationCounter,
        public string $updatedAt,
    ) {}

    public static function fromModel(Ticket $ticket): self
    {
        return new self(
            $ticket->id,
            $ticket->status->value,
            $ticket->qr_rotation_counter,
            CarbonImmutable::instance($ticket->updated_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
