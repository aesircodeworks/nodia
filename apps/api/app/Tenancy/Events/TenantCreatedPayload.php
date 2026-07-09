<?php

namespace App\Tenancy\Events;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class TenantCreatedPayload extends Data
{
    public function __construct(
        public string $tenantId,
        public string $name,
        public string $defaultLocale,
    ) {}
}
