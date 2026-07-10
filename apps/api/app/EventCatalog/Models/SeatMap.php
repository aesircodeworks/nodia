<?php

namespace App\EventCatalog\Models;

use Database\Factories\EventCatalog\Models\SeatMapFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A reusable seating template belonging to a Venue (stage-05b plan, Data
 * model). layout is opaque map-level geometry rendered by the admin
 * frontend, never interpreted by the API.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $venue_id
 * @property string $name
 * @property array<string, mixed> $layout
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read int|null $seats_count set only when a query loads it via
 *   withCount('seats') (SeatMapController::index()'s own usage)
 */
#[Fillable(['tenant_id', 'venue_id', 'name', 'layout'])]
class SeatMap extends Model
{
    /** @use HasFactory<SeatMapFactory> */
    use HasFactory, HasUuids;

    /**
     * @return BelongsTo<Venue, $this>
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /**
     * Ordered by the natural key (section, row, number), never insertion
     * order, so every reader (create's echo, a later stage's show/list)
     * gets the same deterministic sequence without repeating the
     * ordering at every call site (stage-05b plan, TDD sequencing Slice
     * 2: "returns the Data object with seats ordered by section, row,
     * number").
     *
     * @return HasMany<Seat, $this>
     */
    public function seats(): HasMany
    {
        return $this->hasMany(Seat::class)->orderBy('section')->orderBy('row')->orderBy('number');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'layout' => 'array',
        ];
    }
}
