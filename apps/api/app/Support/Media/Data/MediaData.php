<?php

namespace App\Support\Media\Data;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Admin surface response shape shared by every medialibrary-backed
 * endpoint (stage-05c plan, Endpoints: "Admin event media" now, "Admin
 * tenant branding media" in a later task): { id, collection, file_name,
 * mime_type, size, url, conversions, alt_text, order, created_at }. Lives
 * under App\Support\Media, like the Media model and TenantPathGenerator
 * it wraps, because both this task's Event media and a later task's
 * Tenant logo media return the exact same shape; duplicating it per
 * context would drift.
 *
 * fromModel() takes the package's own base Media type, not this app's
 * App\Support\Media\Models\Media subclass: medialibrary's own API
 * (FileAdder::toMediaCollection(), HasMedia::media()) is declared against
 * the base class's generic default, since PHPStan has no way to see the
 * config('media-library.media_model') swap that makes every value
 * actually flowing through it an instance of the subclass at runtime.
 * The base class already declares every property and method this method
 * reads, so nothing is lost by depending on it instead; the one cast
 * below ($media->id to string) exists because the base class's own
 * schema types id as an auto-increment integer, while this app's actual
 * media table (App\Support\Media\Models\Media, HasUuids) always returns
 * a UUID string at runtime.
 */
#[MapName(SnakeCaseMapper::class)]
final class MediaData extends Data
{
    public function __construct(
        public string $id,
        public string $collection,
        public string $fileName,
        public string $mimeType,
        public int $size,
        public string $url,
        public MediaConversionsData $conversions,
        public ?string $altText,
        public int $order,
        public string $createdAt,
    ) {}

    public static function fromModel(Media $media): self
    {
        return new self(
            (string) $media->id,
            $media->collection_name,
            $media->file_name,
            $media->mime_type,
            (int) $media->size,
            $media->getUrl(),
            MediaConversionsData::allNull(),
            $media->getCustomProperty('alt_text'),
            (int) ($media->order_column ?? 0),
            CarbonImmutable::instance($media->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
