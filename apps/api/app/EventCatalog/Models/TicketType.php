<?php

namespace App\EventCatalog\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Database\Factories\EventCatalog\Models\TicketTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A purchasable line item on an Event (stage-05a plan, Data model).
 * tenant_id is denormalized (system-design 4.2) rather than resolved
 * through event_id, matching every other tenant-scoped table's own
 * posture. price is a virtual attribute App\Support\Money\MoneyCast casts
 * onto price_amount/currency (never bare on the wire, ADR 018);
 * requires_seat ships now as a stable column Stage 6 will read (nothing
 * here references seats). max_per_customer is stage-10's per-ticket-type
 * purchase limit (null means unlimited); Inventory reads it only through
 * App\EventCatalog\Data\HoldableTicketTypeData, never this model directly
 * (system-design 3.1, tests/Architecture/ContextBoundariesTest.php).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $event_id
 * @property string $name
 * @property Money $price
 * @property Carbon|null $sales_start
 * @property Carbon|null $sales_end
 * @property bool $requires_seat
 * @property int|null $max_per_customer
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'tenant_id',
    'event_id',
    'name',
    'price',
    'sales_start',
    'sales_end',
    'requires_seat',
    'max_per_customer',
])]
class TicketType extends Model
{
    /** @use HasFactory<TicketTypeFactory> */
    use HasFactory, HasUuids;

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,
            'sales_start' => 'datetime',
            'sales_end' => 'datetime',
            'requires_seat' => 'boolean',
            'max_per_customer' => 'integer',
        ];
    }
}
