<?php

namespace App\Payments\Models;

use App\Payments\Enums\SubmerchantStatus;
use Database\Factories\Payments\Models\SubmerchantAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One tenant's registration with a gateway for split payments and
 * payouts (Sub-merchant, system-design 19; stage-08c plan, Data model
 * "submerchant_accounts"). requirements is the gateway's outstanding
 * requirement keys, persisted verbatim so refresh and webhook handling
 * can render them without another gateway round trip.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $gateway
 * @property SubmerchantStatus $status
 * @property string|null $gateway_account_reference
 * @property string|null $onboarding_url
 * @property list<string> $requirements
 * @property Carbon|null $activated_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'tenant_id',
    'gateway',
    'status',
    'gateway_account_reference',
    'onboarding_url',
    'requirements',
    'activated_at',
])]
class SubmerchantAccount extends Model
{
    /** @use HasFactory<SubmerchantAccountFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubmerchantStatus::class,
            'requirements' => 'array',
            'activated_at' => 'datetime',
        ];
    }
}
