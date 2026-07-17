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

    /*
    |--------------------------------------------------------------------------
    | Protected Generated Media Disk
    |--------------------------------------------------------------------------
    |
    | Ticket PDFs and exports contain credentials or personal data. Their
    | collections explicitly use this private disk instead of the public
    | marketing-media default.
    |
    */

    'protected_disk' => env('PROTECTED_MEDIA_DISK', 'protected-media'),

    /*
    |--------------------------------------------------------------------------
    | Conversion Widths
    |--------------------------------------------------------------------------
    |
    | Fixed target widths for the thumb, card, and hero conversions every
    | image collection in this app registers (stage-05c plan, Data model:
    | "Conversions: thumb, card, hero (fixed widths from config)"). Height
    | is left unconstrained (App\Support\Media\ImageMediaCollections calls
    | only ->width(), preserving aspect ratio) since the plan names widths
    | only, not a crop shape.
    |
    */

    'conversions' => [
        'thumb' => ['width' => (int) env('MEDIA_CONVERSION_THUMB_WIDTH', 320)],
        'card' => ['width' => (int) env('MEDIA_CONVERSION_CARD_WIDTH', 640)],
        'hero' => ['width' => (int) env('MEDIA_CONVERSION_HERO_WIDTH', 1920)],
    ],

];
