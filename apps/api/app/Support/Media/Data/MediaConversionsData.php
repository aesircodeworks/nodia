<?php

namespace App\Support\Media\Data;

use Spatie\LaravelData\Data;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The fixed conversion-name shape every MediaData and (from stage-05c
 * task 4) storefront MediaImageData carries (stage-05c plan, Endpoints:
 * "conversions: { thumb, card, hero }... each conversion value is a
 * nullable URL string, null until the queued conversion completes").
 */
final class MediaConversionsData extends Data
{
    public function __construct(
        public ?string $thumb,
        public ?string $card,
        public ?string $hero,
    ) {}

    public static function allNull(): self
    {
        return new self(null, null, null);
    }

    /**
     * hasGeneratedConversion() is medialibrary's own documented way to
     * tell a not-yet-run queued conversion apart from a completed one
     * (spatie/laravel-medialibrary docs, "Checking for Generated
     * Conversions"); getUrl($name) mirrors the base file's own
     * Media::getUrl() call in MediaData::fromModel().
     */
    public static function fromModel(Media $media): self
    {
        return new self(
            $media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : null,
            $media->hasGeneratedConversion('card') ? $media->getUrl('card') : null,
            $media->hasGeneratedConversion('hero') ? $media->getUrl('hero') : null,
        );
    }
}
