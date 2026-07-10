<?php

namespace App\EventCatalog\Data\Concerns;

use App\EventCatalog\Exceptions\InvalidEventVenueConfigurationException;
use App\EventCatalog\Models\Event;
use App\Support\Tenancy\TenantContext;
use App\Tenancy\Actions\ResolveTenantLocaleSettings;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * Shared request-layer validation for CreateEventData and UpdateEventData
 * (stage-05a plan, task breakdown item 5), wired through each Data
 * class's own withValidator() `after()` hook. Create always has every
 * related key present (its own rules() mark them required), so the
 * "given together" branches below never trigger there; Update makes the
 * three venue/virtual fields and the two schedule fields each an
 * all-or-nothing bundle instead of attempting to merge a partial payload
 * against the target Event's current row (which would need the model,
 * unavailable to a static Data class), so the same three methods serve
 * both without duplicating any branching logic.
 */
trait ValidatesEventInvariants
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $fields
     */
    private static function addTranslatableLocaleErrors(Validator $validator, array $data, array $fields): void
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $settings = app(ResolveTenantLocaleSettings::class)($tenantId);

        foreach ($fields as $field) {
            if (! array_key_exists($field, $data) || ! is_array($data[$field])) {
                continue;
            }

            $locales = array_keys($data[$field]);

            foreach (array_diff($locales, $settings->supportedLocales) as $locale) {
                $validator->errors()->add(
                    "{$field}.{$locale}",
                    sprintf('Locale "%s" is not one of the tenant\'s supported locales.', $locale),
                );
            }

            if (! in_array($settings->defaultLocale, $locales, true)) {
                $validator->errors()->add(
                    $field,
                    sprintf('The %s field must include the tenant\'s default locale ("%s").', $field, $settings->defaultLocale),
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function addVenueOrUrlErrors(Validator $validator, array $data): void
    {
        $keys = ['is_virtual', 'venue_id', 'virtual_event_url'];
        $present = array_values(array_filter($keys, fn (string $key): bool => array_key_exists($key, $data)));

        if ($present === []) {
            return;
        }

        if (count($present) < count($keys)) {
            foreach (array_diff($keys, $present) as $missing) {
                $validator->errors()->add($missing, 'is_virtual, venue_id, and virtual_event_url must be given together.');
            }

            return;
        }

        try {
            Event::assertVenueOrUrlInvariant((bool) $data['is_virtual'], $data['venue_id'], $data['virtual_event_url']);
        } catch (InvalidEventVenueConfigurationException $e) {
            foreach ($keys as $key) {
                $validator->errors()->add($key, $e->getMessage());
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function addScheduleErrors(Validator $validator, array $data): void
    {
        $hasStart = array_key_exists('start_at', $data);
        $hasEnd = array_key_exists('end_at', $data);

        if (! $hasStart && ! $hasEnd) {
            return;
        }

        if ($hasStart !== $hasEnd) {
            $validator->errors()->add($hasStart ? 'end_at' : 'start_at', 'start_at and end_at must be given together.');

            return;
        }

        if ($validator->errors()->has('start_at') || $validator->errors()->has('end_at')) {
            return;
        }

        if (Carbon::parse($data['end_at'])->lessThanOrEqualTo(Carbon::parse($data['start_at']))) {
            $validator->errors()->add('end_at', 'The end_at field must be a date after start_at.');
        }
    }
}
