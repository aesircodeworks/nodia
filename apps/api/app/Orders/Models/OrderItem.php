<?php

namespace App\Orders\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Database\Factories\Orders\Models\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A priced line of an order, snapshotted at conversion time (stage-07
 * plan, Data model "order_items"). ticket_type_id is a cross-context
 * reference with no Eloquent relation into EventCatalog.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $order_id
 * @property string $ticket_type_id
 * @property int $quantity
 * @property Money $unit_price
 * @property int $unit_price_amount
 * @property string $currency
 * @property list<string>|null $attendee_names
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'tenant_id',
    'order_id',
    'ticket_type_id',
    'quantity',
    'unit_price',
    'attendee_names',
])]
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory, HasUuids;

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => MoneyCast::class,
            'attendee_names' => 'array',
        ];
    }
}
