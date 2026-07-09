<?php

namespace App\Tenancy\Models;

use Database\Factories\Tenancy\Models\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property array<string, mixed> $branding_settings
 * @property string $default_locale
 * @property list<string> $supported_locales
 * @property list<string> $enabled_gateways
 * @property array<string, mixed>|null $payout_schedule
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
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
