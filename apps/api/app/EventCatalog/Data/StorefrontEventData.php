<?php

namespace App\EventCatalog\Data;

use App\EventCatalog\Models\Event;
use App\Support\Media\Data\MediaImageData;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Storefront event shape (stage-05a plan, Endpoints, Storefront surface):
 * name and description are resolved to single strings in the negotiated
 * locale (falling back to the tenant default when a translation is
 * missing, per laravel-translatable), locale carries the negotiation
 * result, and ticket_types is always embedded. Raw internal state (status,
 * async_payment_policy) is deliberately absent: the storefront exposes only
 * the published surface.
 *
 * cover_image and gallery (stage-05c plan, Endpoints: "Storefront media
 * exposure") are additive: cover_image is null and gallery is an empty
 * array for an event with no attached media, so the OpenAPI change stays
 * non-breaking.
 */
#[MapName(SnakeCaseMapper::class)]
class StorefrontEventData extends Data
{
    /**
     * @param  DataCollection<int, StorefrontTicketTypeData>  $ticketTypes
     * @param  DataCollection<int, MediaImageData>  $gallery
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public string $locale,
        public string $startAt,
        public string $endAt,
        public string $timezone,
        public bool $isVirtual,
        public ?string $virtualEventUrl,
        #[DataCollectionOf(StorefrontTicketTypeData::class)]
        public DataCollection $ticketTypes,
        public ?MediaImageData $coverImage,
        #[DataCollectionOf(MediaImageData::class)]
        public DataCollection $gallery,
    ) {}

    public static function fromModel(Event $event, string $locale, string $defaultLocale): self
    {
        $cover = $event->getFirstMedia('cover');

        return new self(
            $event->id,
            self::translate($event, 'name', $locale, $defaultLocale),
            self::translate($event, 'description', $locale, $defaultLocale),
            $locale,
            CarbonImmutable::instance($event->start_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            CarbonImmutable::instance($event->end_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            $event->timezone,
            $event->is_virtual,
            $event->virtual_event_url,
            StorefrontTicketTypeData::collect($event->ticketTypes, DataCollection::class),
            $cover !== null ? MediaImageData::fromModel($cover) : null,
            MediaImageData::collect($event->getMedia('gallery'), DataCollection::class),
        );
    }

    private static function translate(Event $event, string $attribute, string $locale, string $defaultLocale): string
    {
        return $event->hasTranslation($attribute, $locale)
            ? $event->getTranslation($attribute, $locale)
            : $event->getTranslation($attribute, $defaultLocale);
    }
}
