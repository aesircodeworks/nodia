<?php

namespace App\Tenancy\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

#[MapName(SnakeCaseMapper::class)]
class CreateTenantData extends Data
{
    /**
     * @param  list<string>  $supportedLocales
     * @param  list<string>|Optional  $enabledGateways
     * @param  array<string, mixed>|Optional|null  $payoutSchedule
     */
    public function __construct(
        public string $name,
        public string $defaultLocale,
        public array $supportedLocales,
        public BrandingSettingsData|Optional $brandingSettings,
        public array|Optional $enabledGateways,
        public array|Optional|null $payoutSchedule,
    ) {}
}
