<?php

namespace App\Reporting\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * GET /v1/exports/{export}/download's response (stage-11 plan,
 * Endpoints: "ExportDownloadData: url (expiring signed object-storage
 * URL), expires_at"). url comes straight from
 * Spatie\MediaLibrary\MediaCollections\Models\Media::getTemporaryUrl(),
 * which asks the media disk's own driver (S3/MinIO) for a presigned
 * URL; this class carries no storage-driver detail of its own.
 */
#[TypeScript]
#[MapName(SnakeCaseMapper::class)]
class ExportDownloadData extends Data
{
    public function __construct(
        public string $url,
        public string $expiresAt,
    ) {}
}
