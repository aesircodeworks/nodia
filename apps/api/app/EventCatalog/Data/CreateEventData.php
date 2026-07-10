<?php

namespace App\EventCatalog\Data;

use App\EventCatalog\Data\Concerns\ValidatesEventInvariants;
use Illuminate\Validation\Validator;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

/**
 * POST /v1/events (stage-05a plan, task breakdown item 5). Drafts are
 * venue-complete at creation (task-03 journal): is_virtual, venue_id, and
 * virtual_event_url are all required keys (nullable value) rather than
 * Optional, so the exactly-one-of invariant always has a complete triple
 * to check. name and description carry the full locale-keyed map (the
 * editing surface); ValidatesEventInvariants::addTranslatableLocaleErrors
 * enforces the tenant default locale is present and no other key exceeds
 * the tenant's supported_locales.
 */
#[MapName(SnakeCaseMapper::class)]
class CreateEventData extends Data
{
    use ValidatesEventInvariants;

    /**
     * @param  array<string, string>  $name
     * @param  array<string, string>  $description
     */
    public function __construct(
        public array $name,
        public array $description,
        public ?string $venueId,
        public bool $isVirtual,
        public ?string $virtualEventUrl,
        public string $startAt,
        public string $endAt,
        public string $timezone,
        public AsyncPaymentPolicyData|Optional $asyncPaymentPolicy,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'array', 'min:1'],
            'name.*' => ['string', 'filled'],
            'description' => ['required', 'array', 'min:1'],
            'description.*' => ['string', 'filled'],
            'venue_id' => ['present', 'nullable', 'uuid'],
            'is_virtual' => ['required', 'boolean'],
            'virtual_event_url' => ['present', 'nullable', 'url'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date'],
            'timezone' => ['required', 'string', 'timezone:all'],
        ];
    }

    public static function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $data = $validator->getData();

            self::addTranslatableLocaleErrors($validator, $data, ['name', 'description']);
            self::addVenueOrUrlErrors($validator, $data);
            self::addScheduleErrors($validator, $data);
        });
    }
}
