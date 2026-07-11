<?php

namespace App\Inventory\Models;

use App\Inventory\Enums\EventSeatStatus;
use Database\Factories\Inventory\Models\EventSeatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One materialized seat on a seated event (stage-06 plan, Data model
 * "event_seats"; system-design 6.2). seat_id and hold_id are real,
 * cross-context FKs (to App\EventCatalog's seats table and this
 * context's own holds table respectively), mirroring
 * App\Inventory\Models\Hold's own event_id/customer_id precedent: the
 * column is a plain FK, never an Eloquent relation into another
 * context's model.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $event_id
 * @property string $seat_id
 * @property string|null $ticket_type_id
 * @property EventSeatStatus $status
 * @property string|null $hold_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'tenant_id',
    'event_id',
    'seat_id',
    'ticket_type_id',
    'status',
    'hold_id',
])]
class EventSeat extends Model
{
    /** @use HasFactory<EventSeatFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EventSeatStatus::class,
        ];
    }
}
