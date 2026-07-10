<?php

namespace App\EventCatalog\Data;

use App\EventCatalog\Support\Iso3166CountryCodes;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

#[MapName(SnakeCaseMapper::class)]
class UpdateVenueData extends Data
{
    public function __construct(
        public string|Optional $name,
        public string|Optional $address,
        public string|Optional $city,
        public string|Optional $country,
        public int|Optional $capacity,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'filled', 'max:255'],
            'address' => ['sometimes', 'string', 'filled', 'max:255'],
            'city' => ['sometimes', 'string', 'filled', 'max:255'],
            'country' => ['sometimes', 'string', Rule::in(Iso3166CountryCodes::ALPHA2)],
            'capacity' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
