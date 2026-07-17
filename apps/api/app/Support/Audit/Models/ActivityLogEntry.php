<?php

namespace App\Support\Audit\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Activitylog\Models\Activity;

/**
 * The uuid-aware Activity subclass task-14's journal flagged as needed
 * before anything could actually record entries ("Task-15 ... will need a
 * uuid-aware Activity model subclass and the activity_model config
 * binding to actually record entries"). Spatie\Activitylog\Models\Activity
 * assumes an auto-incrementing integer key; HasUuids overrides key
 * generation to a v7 uuid, matching every other primary key in this
 * codebase (data-conventions) and the adjusted activity_log migration's
 * own uuid id column (task-14).
 *
 * Lives under App\Support\Audit rather than any bounded context: the
 * activity log is cross-cutting infrastructure every context writes to
 * (App\Support\Audit\ActivityLogger), the same "support" placement
 * App\Support\Tenancy and App\Support\Database already establish for
 * shared infra with no single owning context. tests/Architecture/
 * ContextBoundariesTest only restricts the eight named bounded contexts'
 * own Models and Http namespaces, so this is unrestricted; tests/
 * Architecture/PresetTest.php's Laravel preset (which expects every model
 * under App\Models) is told to ignore this namespace the same way it
 * already ignores App\Tenancy\Models and App\Identity\Models.
 */
class ActivityLogEntry extends Activity
{
    use HasUuids;
}
