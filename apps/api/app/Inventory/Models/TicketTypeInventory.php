<?php

namespace App\Inventory\Models;

use Database\Factories\Inventory\Models\TicketTypeInventoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The narrow counter row backing one ticket type's availability
 * arithmetic (stage-06 plan, Data model "ticket_type_inventory"). Kept
 * free of metadata so hot updates do not contend with catalog reads;
 * `quantity`, `sold`, and `held` are only ever mutated through the
 * conditional UPDATEs in App\Inventory\Actions, never through a bare
 * Eloquent save on this model (master plan test-first rule 2).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $ticket_type_id
 * @property int $quantity
 * @property int $held
 * @property int $sold
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'tenant_id',
    'ticket_type_id',
    'quantity',
    'held',
    'sold',
])]
class TicketTypeInventory extends Model
{
    /** @use HasFactory<TicketTypeInventoryFactory> */
    use HasFactory, HasUuids;

    public $table = 'ticket_type_inventory';
}
