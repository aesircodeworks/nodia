<?php

namespace App\EventCatalog\Support\Search;

use Carbon\CarbonImmutable;

/**
 * One locale's resolved search document content for one event
 * (stage-05c plan, Data model 'event_search_documents'), produced by
 * App\EventCatalog\Support\Search\EventSearchDocumentBuilder. Plain
 * value object, not a laravel-data Data class, mirroring
 * App\Support\Outbox\OutboxEnvelope's own precedent: this is internal
 * transport between the builder and the RefreshSearchIndex consumer /
 * search:rebuild command (a later task in this stage), never a wire
 * shape.
 */
final readonly class EventSearchDocumentRow
{
    public function __construct(
        public string $tenantId,
        public string $eventId,
        public string $locale,
        public string $name,
        public ?string $description,
        public string $regconfig,
        public CarbonImmutable $eventStartsAt,
    ) {}
}
