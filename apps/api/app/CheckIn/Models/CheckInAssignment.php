<?php

namespace App\CheckIn\Models;

use Database\Factories\CheckIn\Models\CheckInAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The event-scoping layer for check-in roles (stage-09 plan, Data model
 * "check_in_assignments"). A role holding checkin.manage bypasses this
 * table; a role holding only checkin.scan needs a row here for the
 * target event, evaluated through the CheckEventAssignment Action.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $event_id
 * @property string $user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'tenant_id',
    'event_id',
    'user_id',
])]
class CheckInAssignment extends Model
{
    /** @use HasFactory<CheckInAssignmentFactory> */
    use HasFactory, HasUuids;
}
