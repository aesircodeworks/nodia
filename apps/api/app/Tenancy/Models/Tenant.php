<?php

namespace App\Tenancy\Models;

use Database\Factories\Tenancy\Models\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'branding_settings', 'default_locale', 'supported_locales', 'enabled_gateways', 'payout_schedule'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branding_settings' => 'array',
            'supported_locales' => 'array',
            'enabled_gateways' => 'array',
            'payout_schedule' => 'array',
        ];
    }
}
