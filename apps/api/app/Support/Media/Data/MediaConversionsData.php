<?php

namespace App\Support\Media\Data;

use Spatie\LaravelData\Data;

/**
 * The fixed conversion-name shape every MediaData and (from stage-05c
 * task 4) storefront MediaImageData carries (stage-05c plan, Endpoints:
 * "conversions: { thumb, card, hero }... each conversion value is a
 * nullable URL string, null until the queued conversion completes").
 * This task (task-02) registers no conversions yet (task-03's job), so
 * every instance built here is always all-null; the key stays present
 * from the start so task-03 only has to start populating values, never
 * add or rename a wire field.
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
}
