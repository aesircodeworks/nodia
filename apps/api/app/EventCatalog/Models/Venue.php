<?php

namespace App\EventCatalog\Models;

use Database\Factories\EventCatalog\Models\VenueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A tenant-scoped physical location events can be held at (stage-05a
 * plan, Data model). country is the raw ISO 3166-1 alpha-2 string,
 * validated at the request layer, not here (see the creating migration's
 * own docblock).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $address
 * @property string $city
 * @property string $country
 * @property int $capacity
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['tenant_id', 'name', 'address', 'city', 'country', 'capacity'])]
class Venue extends Model
{
    /** @use HasFactory<VenueFactory> */
    use HasFactory, HasUuids;
}
