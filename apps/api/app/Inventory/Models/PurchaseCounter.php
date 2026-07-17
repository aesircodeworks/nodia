<?php

namespace App\Inventory\Models;

use Database\Factories\Inventory\Models\PurchaseCounterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One customer's running purchase total against one ticket type's
 * max_per_customer limit (stage-10 plan, Data model "purchase_counters").
 * `quantity` is only ever mutated through the guarded upsert and
 * decrement statements in App\Inventory\Support\PurchaseCounters, never
 * through a bare Eloquent save on this model (master plan test-first
 * rule 2, mirroring TicketTypeInventory's own posture).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $customer_id
 * @property string $ticket_type_id
 * @property int $quantity
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'tenant_id',
    'customer_id',
    'ticket_type_id',
    'quantity',
])]
class PurchaseCounter extends Model
{
    /** @use HasFactory<PurchaseCounterFactory> */
    use HasFactory, HasUuids;
}
