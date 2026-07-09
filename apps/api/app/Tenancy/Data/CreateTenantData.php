<?php

namespace App\Tenancy\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;

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
            'name' => ['required', 'string', 'max:255'],
            'default_locale' => ['required', 'string'],
            'supported_locales' => ['required', 'array', 'list', 'min:1'],
            'supported_locales.*' => ['string', 'filled'],
            'enabled_gateways' => ['sometimes', 'array', 'list'],
            'enabled_gateways.*' => ['string', 'filled'],
            'payout_schedule' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
