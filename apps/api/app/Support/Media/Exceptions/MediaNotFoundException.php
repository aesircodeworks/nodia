<?php

namespace App\Support\Media\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised for a media id that resolves to no visible row: genuinely
 * nonexistent, or a foreign tenant's media the media table's own
 * tenant_isolation RLS policy already hides from a plain find() (stage-05c
 * plan, Endpoints: "DELETE /v1/media/{media} ... 404 request.not_found"),
 * mirroring App\EventCatalog\Exceptions\EventNotFoundException's own
 * precedent. Lives under App\Support\Media rather than a single bounded
 * context's Exceptions directory because the endpoint that throws it
 * (App\Http\Controllers\MediaController) is itself shared infrastructure,
 * not owned by any one context.
 */
final class MediaNotFoundException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $mediaId): self
    {
        return new self(sprintf('No media has id "%s".', $mediaId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RequestNotFound;
    }
}
