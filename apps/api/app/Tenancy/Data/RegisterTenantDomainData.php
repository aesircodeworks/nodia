<?php

namespace App\Tenancy\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

#[MapName(SnakeCaseMapper::class)]
class RegisterTenantDomainData extends Data
{
    public function __construct(
        public string $domain,
        public bool|Optional $isPrimary,
    ) {}
}
