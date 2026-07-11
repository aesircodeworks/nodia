<?php

namespace App\Tenancy\Data;

use App\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

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
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        public ?array $payoutSchedule,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(Tenant $tenant): self
    {
        $brandingSettings = BrandingSettingsData::from($tenant->branding_settings ?? []);

        // getFirstMediaUrl() returns '' (medialibrary's own empty-string
        // convention for "no media", not null) rather than throwing, so
        // an unset logo falls through to whatever the JSON configuration
        // already carries (stage-05c plan, Endpoints: "The logo URL
        // populates the logo_url placeholder Stage 2 already defines on
        // BrandingSettingsData").
        $logoUrl = $tenant->getFirstMediaUrl('logo');

        if ($logoUrl !== '') {
            $brandingSettings->logoUrl = $logoUrl;
        }

        return new self(
            $tenant->id,
            $tenant->name,
            $brandingSettings,
            $tenant->default_locale,
            $tenant->supported_locales,
            $tenant->enabled_gateways,
            $tenant->payout_schedule,
            CarbonImmutable::instance($tenant->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            CarbonImmutable::instance($tenant->updated_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
