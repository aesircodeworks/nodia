<?php

namespace App\Tenancy\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

#[MapName(SnakeCaseMapper::class)]
class UpdateTenantDomainData extends Data
{
    public function __construct(
        public bool|Optional $isPrimary,
    ) {}

    /**
     * Only true is accepted: a domain is demoted by promoting another
     * domain of the same tenant, so a bare demotion has no owning Action
     * and is rejected as unprocessable.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'is_primary' => ['sometimes', 'boolean', 'accepted'],
        ];
    }
}
