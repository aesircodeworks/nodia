<?php

namespace App\Tenancy\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

#[MapName(SnakeCaseMapper::class)]
class UpdateTenantData extends Data
{
    /**
     * @param  list<string>|Optional  $supportedLocales
     * @param  list<string>|Optional  $enabledGateways
     * @param  array<string, mixed>|Optional|null  $payoutSchedule
     */
    public function __construct(
        public string|Optional $name,
        public BrandingSettingsData|Optional $brandingSettings,
        public string|Optional $defaultLocale,
        public array|Optional $supportedLocales,
        public array|Optional $enabledGateways,
        public array|Optional|null $payoutSchedule,
    ) {}
}
