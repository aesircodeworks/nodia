<?php

namespace App\Tenancy\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

// Nullable with defaults rather than Optional so an empty configuration
// still serializes as a JSON object carrying both keys; an all-Optional
// Data object collapses to an empty PHP array, which json_encode renders
// as [] and breaks the wire contract's object typing.
#[MapName(SnakeCaseMapper::class)]
class BrandingSettingsData extends Data
{
    public function __construct(
        public ?string $primaryColor = null,
        public ?string $logoUrl = null,
    ) {}
}
