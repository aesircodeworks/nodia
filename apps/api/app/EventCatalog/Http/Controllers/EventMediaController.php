<?php

namespace App\EventCatalog\Http\Controllers;

use App\EventCatalog\Actions\UploadEventMedia;
use App\EventCatalog\Data\EventMediaListData;
use App\EventCatalog\Data\EventMediaUploadData;
use App\EventCatalog\Exceptions\EventNotFoundException;
use App\EventCatalog\Models\Event;
use App\Support\Media\Data\MediaData;
use Illuminate\Http\JsonResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Event cover and gallery media (stage-05c plan, Endpoints: "Admin event
 * media"). Both routes nest under the owning event per api-conventions;
 * deletion is the top-level DELETE /v1/media/{media} in
 * App\Http\Controllers\MediaController instead, since that route also
 * serves the later tenant-logo collection this context never touches.
 */
class EventMediaController
{
    public function store(string $event, EventMediaUploadData $data, UploadEventMedia $uploadEventMedia): JsonResponse
    {
        return response()->json($uploadEventMedia($this->eventOrFail($event), $data), 201);
    }

    public function index(string $event, EventMediaListData $query): JsonResponse
    {
        $model = $this->eventOrFail($event);

        // Bounded list, no pagination (stage-05c plan, Endpoints:
        // "Bounded list, no pagination, ordered by collection then
        // order_column"): an event's cover-plus-gallery media never
        // approaches a size a paginator would matter for.
        $media = $model->media()
            ->when($query->collection, fn ($builder, string $collection) => $builder->where('collection_name', $collection))
            ->orderBy('collection_name')
            ->orderBy('order_column')
            ->get();

        return response()->json([
            'data' => $media->map(fn (Media $item): MediaData => MediaData::fromModel($item))->all(),
        ]);
    }

    /**
     * Mirrors EventController::eventOrFail(): a well-formed but
     * nonexistent or foreign-tenant event id renders the same generic
     * request.not_found problem a malformed id gets from route-parameter
     * matching.
     */
    private function eventOrFail(string $eventId): Event
    {
        return Event::query()->find($eventId) ?? throw EventNotFoundException::forId($eventId);
    }
}
