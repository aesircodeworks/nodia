<?php

namespace App\Support\Media\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Storefront-facing image shape (stage-05c plan, Endpoints: "Storefront
 * media exposure"): { id, url, conversions: { thumb, card, hero }, alt_text
 * }. Deliberately narrower than the admin App\Support\Media\Data\MediaData
 * shape (no collection, file_name, mime_type, size, order, created_at):
 * the storefront never needs those, per the plan's additive-fields-only
 * contract for cover_image and gallery on StorefrontEventData. Lives
 * alongside MediaData and MediaConversionsData under App\Support\Media for
 * the same reason those do: no single bounded context owns image media.
 */
#[MapName(SnakeCaseMapper::class)]
final class MediaImageData extends Data
{
    public function __construct(
        public string $id,
        public string $url,
        public MediaConversionsData $conversions,
        public ?string $altText,
    ) {}

    public static function fromModel(Media $media): self
    {
        return new self(
            (string) $media->id,
            $media->getUrl(),
            MediaConversionsData::fromModel($media),
            $media->getCustomProperty('alt_text'),
        );
    }
}
