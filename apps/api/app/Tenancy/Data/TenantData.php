<?php

namespace App\Tenancy\Data;

use App\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class TenantData extends Data
{
    /**
     * @param  list<string>  $supportedLocales
     * @param  list<string>  $enabledGateways
     * @param  array<string, mixed>|null  $payoutSchedule
     */
    public function __construct(
        public string $id,
        public string $name,
        public BrandingSettingsData $brandingSettings,
        public string $defaultLocale,
        public array $supportedLocales,
        public array $enabledGateways,
        public ?array $payoutSchedule,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(Tenant $tenant): self
    {
        return new self(
            $tenant->id,
            $tenant->name,
            BrandingSettingsData::from($tenant->branding_settings ?? []),
            $tenant->default_locale,
            $tenant->supported_locales,
            $tenant->enabled_gateways,
            $tenant->payout_schedule,
            CarbonImmutable::instance($tenant->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            CarbonImmutable::instance($tenant->updated_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
