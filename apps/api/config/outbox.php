<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sequence Stability Window
    |--------------------------------------------------------------------------
    |
    | Sequence gaps are permanent and a lower sequence can commit after a
    | higher one (system-design 9.1). The sweeper, ordered consumers, and
    | replay only treat events older than this many seconds as final so an
    | in-flight lower sequence is not missed. Tunable without migration;
    | default proposed in the stage-04 plan (5 seconds).
    |
    */

    'stability_window_seconds' => (int) env('OUTBOX_STABILITY_WINDOW_SECONDS', 5),

    /*
    |--------------------------------------------------------------------------
    | Sweeper Grace Window
    |--------------------------------------------------------------------------
    |
    | The reconciliation sweeper re-enqueues deliveries still pending past
    | this many seconds measured from coalesce(last_enqueued_at, created_at)
    | (system-design 9.2). Covers a crash between commit and enqueue, Redis
    | data loss, and a worker dying mid-job. Default 60 seconds from the
    | stage-04 plan; the sweeper itself is scheduled every minute.
    |
    */

    'sweeper_grace_seconds' => (int) env('OUTBOX_SWEEPER_GRACE_SECONDS', 60),

];
