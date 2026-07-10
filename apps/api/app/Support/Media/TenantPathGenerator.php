<?php

namespace App\Support\Media;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

/**
 * Partitions object storage by tenant on top of the partitioning the
 * `media` table's own RLS policy already gives its rows (stage-05c plan,
 * Data model: "a custom PathGenerator prefixes every stored path with
 * tenant_id/media_uuid/, so tenant assets are partitioned in the bucket as
 * well as in the database"). media_uuid here is medialibrary's own `uuid`
 * column (Spatie\MediaLibrary\MediaCollections\Models\Concerns\HasUuid),
 * not this table's `id` primary key, because that is the identifier the
 * package's own URL and conversion machinery already keys everything on.
 *
 * RLS protects rows; this prefix keeps the corresponding objects
 * auditable and makes per-tenant export and erasure (Stage 12) tractable
 * even though the configured bucket is public-read (stage-05c plan,
 * Risks: "object storage isolation is by path prefix and URL secrecy
 * only").
 */
final class TenantPathGenerator implements PathGenerator
{
    public function getPath(Media $media): string
    {
        return $this->basePath($media);
    }

    public function getPathForConversions(Media $media): string
    {
        return $this->basePath($media).'conversions/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->basePath($media).'responsive-images/';
    }

    private function basePath(Media $media): string
    {
        return "{$media->tenant_id}/{$media->uuid}/";
    }
}
