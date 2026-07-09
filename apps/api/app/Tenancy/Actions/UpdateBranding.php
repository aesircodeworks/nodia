<?php

namespace App\Tenancy\Actions;

use App\Tenancy\Data\TenantData;
use App\Tenancy\Data\UpdateTenantData;
use App\Tenancy\Exceptions\DefaultLocaleNotSupportedException;
use App\Tenancy\Models\Tenant;
use Spatie\LaravelData\Optional;

final class UpdateBranding
{
    public function __invoke(Tenant $tenant, UpdateTenantData $data): TenantData
    {
        $attributes = [];

        if (! $data->name instanceof Optional) {
            $attributes['name'] = $data->name;
        }

        if (! $data->brandingSettings instanceof Optional) {
            $attributes['branding_settings'] = $data->brandingSettings->toArray();
        }

        $defaultLocale = $data->defaultLocale;

        if ($defaultLocale instanceof Optional) {
            $defaultLocale = $tenant->default_locale;
        } else {
            $attributes['default_locale'] = $defaultLocale;
        }

        $supportedLocales = $data->supportedLocales;

        if ($supportedLocales instanceof Optional) {
            $supportedLocales = $tenant->supported_locales;
        } else {
            $attributes['supported_locales'] = $supportedLocales;
        }

        if (! in_array($defaultLocale, $supportedLocales, true)) {
            throw DefaultLocaleNotSupportedException::for($defaultLocale, $supportedLocales);
        }

        $tenant->update($attributes);

        return TenantData::fromModel($tenant);
    }
}
