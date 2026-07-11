<?php

namespace App\Tenancy\Actions;

use App\Support\Media\Data\MediaData;
use App\Tenancy\Data\TenantMediaUploadData;
use App\Tenancy\Models\Tenant;

/**
 * Attaches an uploaded file to a tenant's logo collection (stage-05c
 * plan, Endpoints: "POST /v1/tenants/{tenant}/media ... Single-file
 * collection, replace on re-upload"). Uploading replaces the existing
 * file: medialibrary's own single-file collection behavior
 * (Tenant::registerMediaCollections), needing no extra code here, exactly
 * mirroring App\EventCatalog\Actions\UploadEventMedia's precedent.
 *
 * withProperties(['tenant_id' => $tenant->id]) is required here, unlike
 * UploadEventMedia: this route runs entirely under the platform posture
 * (PlatformRequestTransaction::asPlatform(), stage-05c plan, Endpoints:
 * "logo upload adopts the same platform-scope semantics as the rest of
 * the tenant mutation surface"), where App\Support\Tenancy\TenantContext
 * carries the platform sentinel tenant, not the tenant the logo belongs
 * to. Without this, App\Support\Media\Models\Media's own creating hook
 * would stamp tenant_id with the sentinel, which the media
 * media_platform_write RLS policy would still permit writing (nodia_platform
 * can write under any tenant_id) but would silently misfile the row under
 * the wrong tenant, breaking both the RLS-scoped visibility a later
 * asTenant() read of this row would expect and the tenant_id/media_uuid/
 * object storage prefix.
 */
final class UploadTenantMedia
{
    public function __invoke(Tenant $tenant, TenantMediaUploadData $data): MediaData
    {
        $media = $tenant->addMedia($data->file)
            ->withProperties(['tenant_id' => $tenant->id])
            ->toMediaCollection($data->collection);

        return MediaData::fromModel($media);
    }
}
