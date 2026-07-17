<?php

use App\EventCatalog\Support\StorefrontLocaleNegotiator;
use App\Tenancy\Data\TenantLocaleSettingsData;

/*
 * Stage-05a plan, task breakdown item 10 (Slice 5): the pure locale
 * negotiation resolver, unit-tested without a database. Precedence is
 * explicit query parameter, then Accept-Language, then tenant default,
 * honoring only tenant-supported locales.
 */

function localeSettings(string $default, array $supported): TenantLocaleSettingsData
{
    return new TenantLocaleSettingsData($default, $supported);
}

it('honors an explicit supported locale over Accept-Language and the default', function () {
    $locale = (new StorefrontLocaleNegotiator)->negotiate('fr', 'es', localeSettings('en', ['en', 'fr', 'es']));

    expect($locale)->toBe('fr');
});

it('ignores an explicit unsupported locale and falls through to Accept-Language', function () {
    $locale = (new StorefrontLocaleNegotiator)->negotiate('de', 'es', localeSettings('en', ['en', 'fr', 'es']));

    expect($locale)->toBe('es');
});

it('picks the most-preferred supported Accept-Language when no explicit locale is given', function () {
    $locale = (new StorefrontLocaleNegotiator)->negotiate(null, 'de;q=1.0, fr;q=0.9, es;q=0.8', localeSettings('en', ['en', 'fr', 'es']));

    expect($locale)->toBe('fr');
});

it('matches an Accept-Language region tag to its supported primary subtag', function () {
    $locale = (new StorefrontLocaleNegotiator)->negotiate(null, 'fr-CA', localeSettings('en', ['en', 'fr']));

    expect($locale)->toBe('fr');
});

it('skips an Accept-Language tag explicitly rejected with q=0', function () {
    $locale = (new StorefrontLocaleNegotiator)->negotiate(null, 'fr;q=0, es;q=0.5', localeSettings('en', ['en', 'fr', 'es']));

    expect($locale)->toBe('es');
});

it('falls back to the tenant default when nothing else is supported', function () {
    $locale = (new StorefrontLocaleNegotiator)->negotiate('de', 'it, pt', localeSettings('en', ['en', 'fr']));

    expect($locale)->toBe('en');
});

it('falls back to the tenant default when no signals are present', function () {
    $locale = (new StorefrontLocaleNegotiator)->negotiate(null, null, localeSettings('en', ['en', 'fr']));

    expect($locale)->toBe('en');
});
