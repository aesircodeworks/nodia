<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Media Upload Ceiling
    |--------------------------------------------------------------------------
    |
    | The largest file, in kilobytes, any medialibrary collection in this
    | app accepts (stage-05c plan, Data model: "max size from config
    | (media.max_upload_kb, default 10240)"), enforced as a Laravel `max`
    | validation rule on the request's file property before medialibrary
    | ever sees the upload. Shared across every collection (event cover
    | and gallery now, tenant logo in a later task) rather than one config
    | key per collection, since the plan gives them all the same ceiling.
    |
    */

    'max_upload_kb' => (int) env('MEDIA_MAX_UPLOAD_KB', 10_240),

];
