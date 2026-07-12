<?php

namespace App\CheckIn\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One row of GET /v1/events/{event}/check-in-manifest's response
 * (stage-09 plan, Endpoints "GET /v1/events/{event}/check-in-manifest"):
 * ticket id, status, rotation counter, and the accepted check-in
 * overlay, built by App\CheckIn\Actions\BuildManifest. Deliberately no
 * attendee PII (system-design 11's manifest definition; system-design
 * 14.3's GDPR-surface rationale for keeping PII off door devices).
 */
#[MapName(SnakeCaseMapper::class)]
class ManifestEntryData extends Data
{
    public function __construct(
        public string $ticketId,
        public string $status,
        public int $rotationCounter,
        public ?string $checkedInAt,
    ) {}
}
