<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Export Download URL
    |--------------------------------------------------------------------------
    |
    | How long GET /v1/exports/{export}/download's signed object-storage
    | URL stays valid (stage-11 plan, Endpoints: "url (expiring signed
    | object-storage URL), expires_at"), passed straight to
    | Spatie\MediaLibrary\MediaCollections\Models\Media::getTemporaryUrl(),
    | which asks the underlying disk driver (S3/MinIO in every real
    | environment, config/filesystems.php's media disk) for a presigned
    | URL with this expiry baked in. A fresh download request simply
    | mints a new URL, so this only bounds how long a single link a
    | caller already has stays usable.
    |
    */

    'export_download_url_ttl_minutes' => (int) env('REPORTING_EXPORT_DOWNLOAD_URL_TTL_MINUTES', 15),

];
