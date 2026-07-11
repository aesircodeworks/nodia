<?php

namespace App\Orders\Models;

use App\Orders\Enums\TicketStatus;
use Database\Factories\Orders\Models\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One admission right, issued exactly once on the paid transition
 * (stage-07 plan, Data model "tickets"). The QR payload is computed on
 * render by App\Orders\Support\TicketQrCodec, never stored;
 * qr_rotation_counter bumps invalidate previously rendered payloads
 * (system-design 8.3, 14.4). event_seat_id is a cross-context
 * Inventory reference with no Eloquent relation.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $order_id
 * @property string $ticket_type_id
 * @property string $event_id
 * @property string|null $event_seat_id
 * @property TicketStatus $status
 * @property string|null $attendee_name
 * @property Carbon $issued_at
 * @property int $qr_rotation_counter
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'tenant_id',
    'order_id',
    'ticket_type_id',
    'event_id',
    'event_seat_id',
    'status',
    'attendee_name',
    'issued_at',
    'qr_rotation_counter',
])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
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
            'status' => TicketStatus::class,
            'issued_at' => 'datetime',
            'qr_rotation_counter' => 'integer',
        ];
    }
}
