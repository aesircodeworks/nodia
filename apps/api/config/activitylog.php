<?php

use App\Support\Audit\Models\ActivityLogEntry;
use Spatie\Activitylog\Actions\CleanActivityLogAction;
use Spatie\Activitylog\Actions\LogActivityAction;

// Published from spatie/laravel-activitylog's own config (vendor/spatie/
// laravel-activitylog/config/activitylog.php), the same "adjust, don't
// hand-roll" approach the stage-03 plan takes for the migration itself
// (ADR 014). The only change from the vendor default is activity_model,
// pointed at the uuid-aware subclass over the adjusted activity_log table
// (task-14); every other key is the package's own default, kept
// published so any future code that calls the activity() helper directly
// picks up the same model without needing to know this override exists.
return [

    'enabled' => env('ACTIVITYLOG_ENABLED', true),

    'clean_after_days' => 365,

    'default_log_name' => 'default',

    'default_auth_driver' => null,

    'include_soft_deleted_subjects' => false,

    'activity_model' => ActivityLogEntry::class,

    'default_except_attributes' => [],

    'buffer' => [
        'enabled' => env('ACTIVITYLOG_BUFFER_ENABLED', false),
    ],

    'actions' => [
        'log_activity' => LogActivityAction::class,
        'clean_log' => CleanActivityLogAction::class,
    ],
];
