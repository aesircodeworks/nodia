<?php

namespace App\Orders\Models;

use App\Orders\Enums\OrderStatus;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Database\Factories\Orders\Models\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A buyer's purchase, created by converting a hold (stage-07 plan, Data
 * model "orders"). Every status transition is a conditional UPDATE
 * checked by affected-row count issued from App\Orders\Actions, never a
 * bare ->save() on this model (master plan test-first rule 2).
 * customer_id, event_id, and hold_id are cross-context references with
 * no Eloquent relations into other contexts' models.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $customer_id
 * @property string $event_id
 * @property string|null $promo_code_id
 * @property string $hold_id
 * @property OrderStatus $status
 * @property Money $subtotal
 * @property Money $discount
 * @property Money $fees
 * @property Money $total
 * @property int $subtotal_amount
 * @property int $discount_amount
 * @property int $fees_amount
 * @property int $total_amount
 * @property string $currency
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'tenant_id',
    'customer_id',
    'event_id',
    'promo_code_id',
    'hold_id',
    'status',
    'subtotal',
    'discount',
    'fees',
    'total',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasUuids;

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return BelongsTo<PromoCode, $this>
     */
    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal' => MoneyCast::class,
            'discount' => MoneyCast::class,
            'fees' => MoneyCast::class,
            'total' => MoneyCast::class,
        ];
    }
}
