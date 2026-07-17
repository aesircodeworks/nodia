<?php

namespace App\Tenancy\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Internal cross-context read shape for App\Tenancy\Actions\
 * ResolveTenantLocaleSettings (stage-05a plan, task breakdown item 5):
 * EventCatalog's translatable-content validation needs both the tenant's
 * default_locale and its supported_locales together, so one Action
 * returns both rather than two separate round trips. Hidden from
 * TypeScript generation, mirroring TenantCreatedPayload's precedent: this
 * is never a wire response, only an internal Action return shape.
 */
#[Hidden]
#[MapName(SnakeCaseMapper::class)]
final class TenantLocaleSettingsData extends Data
{
    /**
     * @param  list<string>  $supportedLocales
     */
    public function __construct(
        public string $defaultLocale,
        public array $supportedLocales,
    ) {}
}
