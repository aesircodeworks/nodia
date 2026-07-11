<?php

namespace App\Inventory\Models;

use Database\Factories\Inventory\Models\HoldItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One GA line item of a hold (stage-06 plan, Data model "hold_items").
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $hold_id
 * @property string $ticket_type_id
 * @property int $quantity
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'tenant_id',
    'hold_id',
    'ticket_type_id',
    'quantity',
])]
class HoldItem extends Model
{
    /** @use HasFactory<HoldItemFactory> */
    use HasFactory, HasUuids;

    /**
     * @return BelongsTo<Hold, $this>
     */
    public function hold(): BelongsTo
    {
        return $this->belongsTo(Hold::class);
    }
}
