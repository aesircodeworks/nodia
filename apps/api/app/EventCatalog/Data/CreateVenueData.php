<?php

namespace App\EventCatalog\Data;

use App\EventCatalog\Support\Iso3166CountryCodes;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class CreateVenueData extends Data
{
    public function __construct(
        public string $name,
        public string $address,
        public string $city,
        public string $country,
        public int $capacity,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'filled', 'max:255'],
            'address' => ['required', 'string', 'filled', 'max:255'],
            'city' => ['required', 'string', 'filled', 'max:255'],
            // ISO 3166-1 alpha-2, exact case (stage-05a plan, Data model);
            // the CHECK-backed capacity floor below is the structural
            // backstop for the same invariant this rule guards for country.
            'country' => ['required', 'string', Rule::in(Iso3166CountryCodes::ALPHA2)],
            'capacity' => ['required', 'integer', 'min:1'],
        ];
    }
}
