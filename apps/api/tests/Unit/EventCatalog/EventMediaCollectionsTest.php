<?php

use App\EventCatalog\Models\Event;
use App\Identity\Capability;
use App\Support\Media\ImageMediaCollections;
use Spatie\MediaLibrary\Conversions\Conversion;

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

/*
 * Stage-05c plan, task breakdown item 3 (TDD slice 2): "conversion
 * definitions (thumb, card, hero, fixed widths from config)". Pure PHP,
 * no database: registerAllMediaConversions() only populates the
 * in-memory Conversion collection (spatie/laravel-medialibrary's
 * InteractsWithMedia::$mediaConversions), never touches the media table
 * or the filesystem.
 */

it('registers thumb, card, and hero conversions with fixed widths read from config, each queued and applying to every collection', function () {
    config()->set('media.conversions', [
        'thumb' => ['width' => 320],
        'card' => ['width' => 640],
        'hero' => ['width' => 1920],
    ]);

    $event = new Event;
    $event->registerAllMediaConversions();

    $conversions = collect($event->mediaConversions)->keyBy(fn (Conversion $conversion) => $conversion->getName());

    expect($conversions->keys()->sort()->values()->all())->toBe(['card', 'hero', 'thumb']);

    foreach (['thumb' => 320, 'card' => 640, 'hero' => 1920] as $name => $width) {
        expect($conversions[$name]->getManipulations()->getFirstManipulationArgument('width'))->toBe($width)
            ->and($conversions[$name]->shouldBeQueued())->toBeTrue()
            ->and($conversions[$name]->shouldBePerformedOn('cover'))->toBeTrue()
            ->and($conversions[$name]->shouldBePerformedOn('gallery'))->toBeTrue();
    }
});

it('reads each conversion width from ImageMediaCollections::conversionWidth()', function () {
    config()->set('media.conversions.card.width', 700);

    expect(ImageMediaCollections::conversionWidth('card'))->toBe(700);
});

it('dispatches queued conversions on the dedicated media-conversions Horizon queue', function () {
    expect(config()->string('media-library.queue_name'))->toBe('media-conversions');
});
