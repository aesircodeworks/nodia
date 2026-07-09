<?php

namespace App\Tenancy\Actions;

use App\Tenancy\Data\CreateTenantData;
use App\Tenancy\Data\TenantData;
use App\Tenancy\Exceptions\DefaultLocaleNotSupportedException;
use App\Tenancy\Models\Tenant;
use Spatie\LaravelData\Optional;

final class CreateTenant
{
    public function __invoke(CreateTenantData $data): TenantData
    {
        if (! in_array($data->defaultLocale, $data->supportedLocales, true)) {
            throw DefaultLocaleNotSupportedException::for($data->defaultLocale, $data->supportedLocales);
        }

        $tenant = Tenant::create([
            'name' => $data->name,
            'default_locale' => $data->defaultLocale,
            'supported_locales' => $data->supportedLocales,
            'branding_settings' => $data->brandingSettings instanceof Optional ? [] : $data->brandingSettings->toArray(),
            'enabled_gateways' => $data->enabledGateways instanceof Optional ? [] : $data->enabledGateways,
            'payout_schedule' => $data->payoutSchedule instanceof Optional ? null : $data->payoutSchedule,
        ]);

        return TenantData::fromModel($tenant);
    }
}
