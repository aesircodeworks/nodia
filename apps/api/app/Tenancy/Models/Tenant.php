<?php

namespace App\Tenancy\Models;

use App\Identity\Capability;
use App\Payments\Enums\RefundCommissionPolicy;
use App\Support\Media\Contracts\HasMediaCapability;
use App\Support\Media\ImageMediaCollections;
use Database\Factories\Tenancy\Models\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

/**
 * @property string $id
 * @property string $name
 * @property array<string, mixed> $branding_settings
 * @property string $default_locale
 * @property list<string> $supported_locales
 * @property list<string> $enabled_gateways
 * @property array<string, mixed>|null $payout_schedule
 * @property string $settlement_currency
 * @property int $commission_bps
 * @property RefundCommissionPolicy $refund_commission_policy
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['name', 'branding_settings', 'default_locale', 'supported_locales', 'enabled_gateways', 'payout_schedule', 'settlement_currency', 'commission_bps', 'refund_commission_policy'])]
class Tenant extends Model implements HasMedia, HasMediaCapability
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUuids, InteractsWithMedia;

    /**
     * Mirrors the column defaults so a freshly created model serializes
     * without a round-trip re-read; the DB defaults remain the source
     * of truth for rows created outside Eloquent.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'commission_bps' => 0,
        'refund_commission_policy' => 'retained',
    ];

    /**
     * logo is single-file: re-uploading replaces the existing file
     * (stage-05c plan, Data model: "Tenant: logo (single file)"), same
     * accepted mime types as Event's cover and gallery, factored out to
     * App\Support\Media\ImageMediaCollections rather than repeated here.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')
            ->singleFile()
            ->acceptsMimeTypes(ImageMediaCollections::ACCEPTED_MIME_TYPES);
    }

    /**
     * thumb, card, and hero, queued on the dedicated Horizon queue,
     * the same three conversions Event registers (stage-05c plan, Data
     * model: "Conversions: thumb, card, hero").
     */
    public function registerMediaConversions(?SpatieMedia $media = null): void
    {
        ImageMediaCollections::registerConversions($this);
    }

    /**
     * Tenant branding logo mutations gate on tenants.manage (stage-05c
     * plan, Endpoints: "logo upload adopts the same platform-scope
     * semantics as the rest of the tenant mutation surface").
     */
    public function mediaManageCapability(): Capability
    {
        return Capability::TenantsManage;
    }

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
            'commission_bps' => 'integer',
            'refund_commission_policy' => RefundCommissionPolicy::class,
        ];
    }
}
