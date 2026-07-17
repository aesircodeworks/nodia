<?php

namespace App\EventCatalog\Data;

use App\EventCatalog\Data\Concerns\ValidatesEventInvariants;
use Illuminate\Validation\Validator;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

/**
 * PATCH /v1/events/{event} (stage-05a plan, task breakdown item 5). Every
 * field is Optional for a partial update; is_virtual/venue_id/
 * virtual_event_url and start_at/end_at are each an all-or-nothing bundle
 * (ValidatesEventInvariants) rather than merged against the target
 * Event's current row, since a static Data class has no model to merge
 * against. seat_map_id (stage-05b plan, Endpoints: "PATCH /v1/events/
 * {event} (extension of the Stage 5a endpoint)") is validated here only
 * for shape (a nullable uuid); the domain check that it belongs to the
 * event's effective venue, and the reverse-direction re-validation when
 * venue_id or is_virtual changes while seat_map_id is set, both need a
 * database read and the target Event's current row, so they live in
 * App\EventCatalog\Actions\UpdateEvent instead, mirroring
 * catalog.currency_mismatch's own precedent (CreateTicketTypeData's
 * docblock) of a boundary check living in the Action rather than here.
 */
#[MapName(SnakeCaseMapper::class)]
class UpdateEventData extends Data
{
    use ValidatesEventInvariants;

    /**
     * @param  array<string, string>|Optional  $name
     * @param  array<string, string>|Optional  $description
     */
    public function __construct(
        public array|Optional $name,
        public array|Optional $description,
        public string|Optional|null $venueId,
        public bool|Optional $isVirtual,
        public string|Optional|null $virtualEventUrl,
        public string|Optional $startAt,
        public string|Optional $endAt,
        public string|Optional $timezone,
        public AsyncPaymentPolicyData|Optional $asyncPaymentPolicy,
        public OnSalePolicyData|Optional $onSalePolicy,
        public string|Optional|null $seatMapId,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['sometimes', 'array', 'min:1'],
            'name.*' => ['string', 'filled'],
            'description' => ['sometimes', 'array', 'min:1'],
            'description.*' => ['string', 'filled'],
            // exists runs under the acting tenant's RLS context, rejecting
            // a venue belonging to another tenant (see CreateEventData).
            'venue_id' => ['sometimes', 'nullable', 'uuid', 'exists:venues,id'],
            'is_virtual' => ['sometimes', 'boolean'],
            'virtual_event_url' => ['sometimes', 'nullable', 'url'],
            'start_at' => ['sometimes', 'date'],
            'end_at' => ['sometimes', 'date'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'seat_map_id' => ['sometimes', 'nullable', 'uuid'],
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
