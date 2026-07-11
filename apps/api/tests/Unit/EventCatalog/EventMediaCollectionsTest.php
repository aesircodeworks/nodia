<?php

use App\EventCatalog\Models\Event;
use App\Identity\Capability;
use App\Support\Media\ImageMediaCollections;

/*
 * Stage-05c plan, TDD sequencing Slice 2: "Unit (failing): collection
 * registration (accepted mimes, single-file semantics)". Pure PHP, no
 * database: Event::registerMediaCollections() only mutates an in-memory
 * MediaCollection definition (spatie/laravel-medialibrary's
 * InteractsWithMedia::getMediaCollection()), never touches the media
 * table.
 */

it('registers cover as a single-file collection accepting only the configured image mime types', function () {
    $collection = (new Event)->getMediaCollection('cover');

    expect($collection)->not->toBeNull()
        ->and($collection->singleFile)->toBeTrue()
        ->and($collection->acceptsMimeTypes)->toBe(ImageMediaCollections::ACCEPTED_MIME_TYPES);
});

it('registers gallery as a multi-file collection accepting the same image mime types as cover', function () {
    $collection = (new Event)->getMediaCollection('gallery');

    expect($collection)->not->toBeNull()
        ->and($collection->singleFile)->toBeFalse()
        ->and($collection->acceptsMimeTypes)->toBe(ImageMediaCollections::ACCEPTED_MIME_TYPES);
});

it('reads the max upload size from the media.max_upload_kb config', function () {
    config()->set('media.max_upload_kb', 2048);

    expect(ImageMediaCollections::maxUploadKilobytes())->toBe(2048);
});

it('gates event media mutations on events.manage, the same capability every other event mutation uses', function () {
    expect((new Event)->mediaManageCapability())->toBe(Capability::EventsManage);
});
