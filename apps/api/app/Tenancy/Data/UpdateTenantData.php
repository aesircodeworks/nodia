<?php

namespace App\Tenancy\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;

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
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        public array|Optional|null $payoutSchedule,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'filled', 'max:255'],
            'default_locale' => ['sometimes', 'string', 'filled'],
            'supported_locales' => ['sometimes', 'array', 'list', 'min:1'],
            'supported_locales.*' => ['string', 'filled'],
            'enabled_gateways' => ['sometimes', 'array', 'list'],
            'enabled_gateways.*' => ['string', 'filled'],
            'payout_schedule' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
