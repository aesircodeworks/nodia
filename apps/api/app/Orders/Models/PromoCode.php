<?php

namespace App\Orders\Models;

use App\Orders\Enums\PromoCodeDiscountType;
use Database\Factories\Orders\Models\PromoCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A tenant's promo code (stage-07 plan, Data model "promo_codes").
 * discount_value is basis points for percentage codes and minor units
 * for fixed_amount codes. usage_count only ever moves through
 * ApplyPromoCode's conditional UPDATE, never a bare ->save() (master
 * plan test-first rule 2).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property PromoCodeDiscountType $discount_type
 * @property int $discount_value
 * @property string|null $currency
 * @property int|null $usage_limit
 * @property int $usage_count
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'tenant_id',
    'code',
    'discount_type',
    'discount_value',
    'currency',
    'usage_limit',
    'usage_count',
    'valid_from',
    'valid_to',
])]
class PromoCode extends Model
{
    /** @use HasFactory<PromoCodeFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discount_type' => PromoCodeDiscountType::class,
            'discount_value' => 'integer',
            'usage_limit' => 'integer',
            'usage_count' => 'integer',
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
        ];
    }
}
