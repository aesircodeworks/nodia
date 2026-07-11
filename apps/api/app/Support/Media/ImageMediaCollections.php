<?php

namespace App\Support\Media;

/**
 * The accepted mime types and size ceiling shared by every image
 * collection this app registers (stage-05c plan, Data model: "Accepted
 * mime types image/jpeg, image/png, image/webp" for both Event's cover
 * and gallery collections here and Tenant's logo collection in a later
 * task, "same accepted types"). Factored out under App\Support\Media
 * rather than duplicated on each model, mirroring why the Media model and
 * TenantPathGenerator live here too: no single bounded context owns this
 * rule, and Event (this task) and Tenant (a later task) both need the
 * identical list rather than each declaring its own copy that could drift.
 */
final class ImageMediaCollections
{
    /** @var list<string> */
    public const array ACCEPTED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public static function maxUploadKilobytes(): int
    {
        return config()->integer('media.max_upload_kb');
    }
}
