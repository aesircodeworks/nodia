<?php

namespace App\EventCatalog\Actions;

use App\EventCatalog\Data\EventMediaUploadData;
use App\EventCatalog\Models\Event;
use App\Support\Media\Data\MediaData;

/**
 * Attaches an uploaded file to an event's cover or gallery collection
 * (stage-05c plan, Endpoints: "POST /v1/events/{event}/media"). Uploading
 * to cover replaces the existing file: that is medialibrary's own
 * single-file collection behavior (Event::registerMediaCollections),
 * needing no extra code here. No domain event is recorded (stage-05c
 * plan, Domain events: "Media mutations do not record domain events;
 * nothing downstream consumes them").
 */
final class UploadEventMedia
{
    public function __invoke(Event $event, EventMediaUploadData $data): MediaData
    {
        $media = $event->addMedia($data->file)->toMediaCollection($data->collection);

        return MediaData::fromModel($media);
    }
}
