<?php

namespace App\Tenancy\Actions;

use App\Support\Outbox\OutboxRecorder;
use App\Tenancy\Data\CreateTenantData;
use App\Tenancy\Data\TenantData;
use App\Tenancy\Events\TenantCreated;
use App\Tenancy\Exceptions\DefaultLocaleNotSupportedException;
use App\Tenancy\Models\Tenant;
use Spatie\LaravelData\Optional;

/**
 * POST /v1/tenants (stage-02 plan). The whole platform request already runs
 * inside one database transaction under the sentinel platform tenant
 * (PlatformRequestTransaction), so this Action needs no transaction of its
 * own. TenantCreated is recorded into the outbox in that same transaction
 * with the sentinel in the envelope (stage-04 plan, Slice 6).
 */
final class CreateTenant
{
    public function __construct(
        private readonly OutboxRecorder $outbox,
    ) {}

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

        $this->outbox->record(TenantCreated::fromTenant($tenant));

        return TenantData::fromModel($tenant);
    }
}
