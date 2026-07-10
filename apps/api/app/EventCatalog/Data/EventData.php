<?php

namespace App\EventCatalog\Data;

use App\EventCatalog\Models\Event;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

/**
 * Admin surface response shape (stage-05a plan, Endpoints: "EventData
 * carries name and description as full locale-keyed maps ... and money
 * nowhere"). venue is Optional when the venue relation was not requested
 * via include=venue (task breakdown item 5); ticket_types is not part of
 * this shape until task breakdown item 8 lands TicketType.
 */
#[MapName(SnakeCaseMapper::class)]
class EventData extends Data
{
    /**
     * @param  array<string, string>  $name
     * @param  array<string, string>  $description
     */
    public function __construct(
        public string $id,
        public string $tenantId,
        public ?string $venueId,
        public VenueData|Optional|null $venue,
        public string $status,
        public array $name,
        public array $description,
        public string $startAt,
        public string $endAt,
        public string $timezone,
        public bool $isVirtual,
        public ?string $virtualEventUrl,
        public AsyncPaymentPolicyData $asyncPaymentPolicy,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(Event $event): self
    {
        return new self(
            $event->id,
            $event->tenant_id,
            $event->venue_id,
            self::venueFromModel($event),
            $event->status->value,
            $event->getTranslations('name'),
            $event->getTranslations('description'),
            CarbonImmutable::instance($event->start_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            CarbonImmutable::instance($event->end_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            $event->timezone,
            $event->is_virtual,
            $event->virtual_event_url,
            $event->async_payment_policy,
            CarbonImmutable::instance($event->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            CarbonImmutable::instance($event->updated_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }

    private static function venueFromModel(Event $event): VenueData|Optional|null
    {
        if (! $event->relationLoaded('venue')) {
            return Optional::create();
        }

        return $event->venue === null ? null : VenueData::fromModel($event->venue);
    }
}
