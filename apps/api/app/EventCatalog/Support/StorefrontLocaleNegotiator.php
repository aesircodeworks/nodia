<?php

namespace App\EventCatalog\Support;

use App\Tenancy\Data\TenantLocaleSettingsData;

/**
 * Storefront locale negotiation (system-design 12, stage-05a plan, Slice
 * 5): an explicit locale query parameter wins when it names a tenant-
 * supported locale, then the Accept-Language header's most-preferred
 * supported language, then the tenant default. Only tenant-supported
 * locales are ever honored; anything else is ignored and the chain
 * continues. Section 12's customer-preference step is out of scope here
 * because these storefront reads carry no customer identity (Stage 7 adds
 * it to the resolver once customer-facing order endpoints exist).
 */
final class StorefrontLocaleNegotiator
{
    public function negotiate(?string $explicit, ?string $acceptLanguage, TenantLocaleSettingsData $settings): string
    {
        if ($explicit !== null && in_array($explicit, $settings->supportedLocales, true)) {
            return $explicit;
        }

        foreach ($this->acceptLanguageCandidates($acceptLanguage) as $candidate) {
            if (in_array($candidate, $settings->supportedLocales, true)) {
                return $candidate;
            }
        }

        return $settings->defaultLocale;
    }

    /**
     * The Accept-Language tags in descending q-value order (ties broken by
     * their header position), each followed by its primary subtag so a
     * "fr-CA" preference still matches a tenant that supports plain "fr".
     * A tag weighted q=0 explicitly rejects that language and is dropped.
     *
     * @return list<string>
     */
    private function acceptLanguageCandidates(?string $header): array
    {
        if ($header === null || trim($header) === '') {
            return [];
        }

        $weighted = [];

        foreach (explode(',', $header) as $index => $part) {
            $segments = explode(';', trim($part));
            $tag = trim($segments[0]);

            if ($tag === '' || $tag === '*') {
                continue;
            }

            $quality = 1.0;

            foreach (array_slice($segments, 1) as $segment) {
                [$key, $value] = array_pad(explode('=', trim($segment), 2), 2, '');

                if (trim($key) === 'q') {
                    $quality = (float) $value;
                }
            }

            if ($quality <= 0.0) {
                continue;
            }

            $weighted[] = ['q' => $quality, 'order' => $index, 'tag' => $tag];
        }

        usort($weighted, fn (array $a, array $b): int => $b['q'] <=> $a['q'] ?: $a['order'] <=> $b['order']);

        $candidates = [];

        foreach ($weighted as $entry) {
            $candidates[] = $entry['tag'];
            $primary = strtok($entry['tag'], '-');

            if ($primary !== $entry['tag']) {
                $candidates[] = $primary;
            }
        }

        return array_values(array_unique($candidates));
    }
}
