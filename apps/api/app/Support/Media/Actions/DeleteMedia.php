<?php

namespace App\Support\Media\Actions;

use App\Support\Media\Models\Media;

/**
 * Deletes a media row along with its stored file and conversions
 * (stage-05c plan, Endpoints: "DELETE /v1/media/{media} ... Deletes the
 * media row, its stored file, and conversions"). The file removal itself
 * is medialibrary's own MediaObserver::deleted() hook, wired to whatever
 * model config('media-library.media_model') names (this app's own Media
 * subclass); this Action exists only so
 * App\Http\Controllers\MediaController calls a testable unit rather than
 * an Eloquent method directly, mirroring every other mutation in this
 * codebase going through its own Action class.
 */
final class DeleteMedia
{
    public function __invoke(Media $media): void
    {
        $media->delete();
    }
}
