<?php

use App\EventCatalog\Exceptions\InvalidEventVenueConfigurationException;
use App\EventCatalog\Models\Event;
use Illuminate\Support\Str;

/*
 * The exactly-one-of-venue-or-url application invariant (stage-05a plan,
 * Data model, the CHECK the events migration ships:
 * "(is_virtual AND venue_id IS NULL AND virtual_event_url IS NOT NULL) OR
 * (NOT is_virtual AND venue_id IS NOT NULL AND virtual_event_url IS
 * NULL)"). Pure and DB-free: Event::assertVenueOrUrlInvariant() is
 * exercised directly rather than through a real save, so this proves the
 * invariant without depending on Postgres or the RLS regime, mirroring
 * MembershipScopeInvariantTest's own precedent for the identical
 * situation.
 */

it('allows a virtual event with no venue and a url', function () {
    Event::assertVenueOrUrlInvariant(true, null, 'https://example.test/stream');
})->throwsNoExceptions();

it('allows a physical event with a venue and no url', function () {
    Event::assertVenueOrUrlInvariant(false, (string) Str::uuid7(), null);
})->throwsNoExceptions();

it('rejects a virtual event that also carries a venue_id', function () {
    $venueId = (string) Str::uuid7();

    expect(fn () => Event::assertVenueOrUrlInvariant(true, $venueId, 'https://example.test/stream'))
        ->toThrow(InvalidEventVenueConfigurationException::class, $venueId);
});

it('rejects a virtual event missing its virtual_event_url', function () {
    expect(fn () => Event::assertVenueOrUrlInvariant(true, null, null))
        ->toThrow(InvalidEventVenueConfigurationException::class);
});

it('rejects a physical event missing its venue_id', function () {
    expect(fn () => Event::assertVenueOrUrlInvariant(false, null, null))
        ->toThrow(InvalidEventVenueConfigurationException::class);
});

it('rejects a physical event that also carries a virtual_event_url', function () {
    $venueId = (string) Str::uuid7();

    expect(fn () => Event::assertVenueOrUrlInvariant(false, $venueId, 'https://example.test/stream'))
        ->toThrow(InvalidEventVenueConfigurationException::class, $venueId);
});
