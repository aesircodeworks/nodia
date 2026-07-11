<?php

namespace App\Inventory\Models;

use App\Inventory\Enums\HoldStatus;
use Database\Factories\Inventory\Models\HoldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A buyer's temporary claim on inventory (stage-06 plan, Data model
 * "holds"). Every status transition is a conditional UPDATE checked by
 * affected-row count issued from App\Inventory\Actions, never a bare
 * ->save() on this model (master plan test-first rule 2).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $event_id
 * @property string|null $customer_id
 * @property HoldStatus $status
 * @property Carbon $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'tenant_id',
    'event_id',
    'customer_id',
    'status',
    'expires_at',
])]
class Hold extends Model
{
    /** @use HasFactory<HoldFactory> */
    use HasFactory, HasUuids;

    /**
     * @return HasMany<HoldItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(HoldItem::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => HoldStatus::class,
            'expires_at' => 'datetime',
        ];
    }
}
