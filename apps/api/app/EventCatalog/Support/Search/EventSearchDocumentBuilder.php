<?php

namespace App\EventCatalog\Support\Search;

use App\EventCatalog\Models\Event;
use App\Tenancy\Actions\ResolveTenantLocaleSettings;
use Carbon\CarbonImmutable;

/**
 * Resolves an event into one App\EventCatalog\Support\Search\
 * EventSearchDocumentRow per tenant-supported locale (stage-05c plan,
 * Data model 'event_search_documents': "Locale coverage is every
 * tenant-supported locale, with laravel-translatable fallback applied at
 * document-build time"). The tenant's locale configuration is
 * Tenancy-owned, so it is obtained through
 * App\Tenancy\Actions\ResolveTenantLocaleSettings rather than querying
 * the tenants table directly (system-design 3.1 boundary rule,
 * tests/Architecture/ContextBoundariesTest); that Action already exists
 * (stage-05a plan, task breakdown item 5) and already returns exactly
 * default_locale and supported_locales together, so this task reuses it
 * rather than adding a second Tenancy read Action for the same two
 * columns (see the stage-05c task-06 journal entry for this deviation).
 *
 * name and description resolve independently per locale:
 * hasTranslation() decides whether the requested locale has its own
 * content; when it does not, the tenant's default_locale content is used
 * instead (mirroring App\EventCatalog\Data\StorefrontEventData::translate's
 * own fallback precedent). description stays null when neither the
 * requested locale nor the default locale has content, matching the
 * table's own nullable column; name is never null in practice because
 * Stage 5a's translatable-content validation requires every event to
 * carry its name in the tenant default locale, but the empty-string
 * fallback here keeps the return type a plain string regardless.
 *
 * regconfigFor() is the locale-to-text-search-configuration mapping the
 * plan specifies literally: pt to portuguese, en to english, every other
 * locale (including one the tenant supports but this mapping does not
 * yet recognize) to simple. searchVectorSql()/searchVectorBindings()
 * build the raw SQL expression and its bindings for the projector (a
 * later task's RefreshSearchIndex consumer and search:rebuild command)
 * to embed in its own INSERT ... ON CONFLICT upsert; setweight() is a
 * PostgreSQL function with no laravel-data or query-builder equivalent,
 * so it is composed here as parameterized raw SQL rather than string
 * interpolation, keeping the regconfig and text values themselves bound
 * rather than concatenated even though this fixed three-value mapping
 * carries no realistic injection risk.
 */
final class EventSearchDocumentBuilder
{
    public function __construct(private readonly ResolveTenantLocaleSettings $resolveTenantLocaleSettings) {}

    /**
     * @return list<EventSearchDocumentRow>
     */
    public function documentsFor(Event $event): array
    {
        $settings = ($this->resolveTenantLocaleSettings)($event->tenant_id);

        return array_map(
            fn (string $locale): EventSearchDocumentRow => $this->documentForLocale($event, $locale, $settings->defaultLocale),
            $settings->supportedLocales,
        );
    }

    public static function regconfigFor(string $locale): string
    {
        return match ($locale) {
            'pt' => 'portuguese',
            'en' => 'english',
            default => 'simple',
        };
    }

    /**
     * setweight(to_tsvector(config, name), 'A') || setweight(to_tsvector(config,
     * description), 'B'), per the plan's Data model section, quoted
     * verbatim. coalesce(..., '') stands in for a null description: an
     * empty document contributes no lexemes, so the concatenation still
     * yields a valid (if name-only) tsvector.
     */
    public static function searchVectorSql(): string
    {
        return "setweight(to_tsvector(?::regconfig, ?), 'A') || setweight(to_tsvector(?::regconfig, coalesce(?, '')), 'B')";
    }

    /**
     * @return list<string|null>
     */
    public static function searchVectorBindings(EventSearchDocumentRow $row): array
    {
        return [$row->regconfig, $row->name, $row->regconfig, $row->description];
    }

    private function documentForLocale(Event $event, string $locale, string $defaultLocale): EventSearchDocumentRow
    {
        return new EventSearchDocumentRow(
            tenantId: $event->tenant_id,
            eventId: $event->id,
            locale: $locale,
            name: self::resolveTranslation($event, 'name', $locale, $defaultLocale) ?? '',
            description: self::resolveTranslation($event, 'description', $locale, $defaultLocale),
            regconfig: self::regconfigFor($locale),
            eventStartsAt: CarbonImmutable::instance($event->start_at),
        );
    }

    private static function resolveTranslation(Event $event, string $attribute, string $locale, string $defaultLocale): ?string
    {
        $resolvedLocale = $event->hasTranslation($attribute, $locale) ? $locale : $defaultLocale;

        if (! $event->hasTranslation($attribute, $resolvedLocale)) {
            return null;
        }

        return $event->getTranslation($attribute, $resolvedLocale, false);
    }
}
