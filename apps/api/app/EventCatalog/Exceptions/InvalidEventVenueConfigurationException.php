<?php

namespace App\EventCatalog\Exceptions;

use InvalidArgumentException;

/**
 * Guards the exactly-one-of-venue-or-url application invariant (stage-05a
 * plan, Data model: "(is_virtual AND venue_id IS NULL AND
 * virtual_event_url IS NOT NULL) OR (NOT is_virtual AND venue_id IS NOT
 * NULL AND virtual_event_url IS NULL)"). A caller hitting this has a bug,
 * not bad user input: the request layer (App\EventCatalog\Data\
 * CreateEventData, a later task) is the friendly-error gate that rejects
 * bad input before an Event model is ever built, and the events_venue_or_url
 * CHECK constraint is the structural backstop below both. This never
 * implements HasErrorCode and is not meant to reach the HTTP boundary,
 * mirroring App\Identity\Exceptions\InvalidMembershipScopeException's own
 * precedent for the identical situation.
 */
final class InvalidEventVenueConfigurationException extends InvalidArgumentException
{
    public static function forCombination(bool $isVirtual, ?string $venueId, ?string $virtualEventUrl): self
    {
        return new self(sprintf(
            'An event must have exactly one of venue_id or virtual_event_url: is_virtual=%s, venue_id=%s, virtual_event_url=%s.',
            $isVirtual ? 'true' : 'false',
            $venueId ?? 'null',
            $virtualEventUrl ?? 'null',
        ));
    }
}
