<?php

namespace App\EventCatalog\Models;

use Database\Factories\EventCatalog\Models\SeatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single seat on a SeatMap template (stage-05b plan, Data model). Its id
 * is a stable identity a later stage's event_seats.seat_id will reference,
 * so an upsert that only moves position_x/position_y must preserve it for
 * an unchanged (section, row, number) natural key. position_x and
 * position_y are integer layout grid units, never floats (stage-05b plan,
 * Risks: "Coordinate representation").
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $seat_map_id
 * @property string $section
 * @property string $row
 * @property string $number
 * @property int|null $position_x
 * @property int|null $position_y
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['tenant_id', 'seat_map_id', 'section', 'row', 'number', 'position_x', 'position_y'])]
class Seat extends Model
{
    /** @use HasFactory<SeatFactory> */
    use HasFactory, HasUuids;

    /**
     * @return BelongsTo<SeatMap, $this>
     */
    public function seatMap(): BelongsTo
    {
        return $this->belongsTo(SeatMap::class);
    }
}
