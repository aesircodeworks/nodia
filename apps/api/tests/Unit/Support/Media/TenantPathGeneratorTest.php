<?php

use App\Support\Media\Models\Media;
use App\Support\Media\TenantPathGenerator;

/*
 * Stage-05c plan, Data model 'media' / TDD sequencing Slice 1: the custom
 * PathGenerator prefixes every stored path with tenant_id/media_uuid/, so
 * tenant assets are partitioned in the object storage bucket as well as
 * in the database. media_uuid is medialibrary's own `uuid` column
 * (Spatie\MediaLibrary\MediaCollections\Models\Concerns\HasUuid), not
 * this table's `id` primary key. Pure in-memory: no database round trip
 * is needed to exercise a path generator, which only ever reads
 * attributes already present on the in-memory Media instance.
 */

it('prefixes the original path with tenant_id/media_uuid/', function () {
    $media = new Media(['tenant_id' => '019797f0-0000-7000-8000-00000000000a']);
    $media->uuid = '4b1f2e2a-1234-4a56-8abc-1234567890ab';

    $path = (new TenantPathGenerator)->getPath($media);

    expect($path)->toBe('019797f0-0000-7000-8000-00000000000a/4b1f2e2a-1234-4a56-8abc-1234567890ab/');
});

it('prefixes the conversions path with tenant_id/media_uuid/', function () {
    $media = new Media(['tenant_id' => '019797f0-0000-7000-8000-00000000000a']);
    $media->uuid = '4b1f2e2a-1234-4a56-8abc-1234567890ab';

    $path = (new TenantPathGenerator)->getPathForConversions($media);

    expect($path)->toBe('019797f0-0000-7000-8000-00000000000a/4b1f2e2a-1234-4a56-8abc-1234567890ab/conversions/');
});

it('prefixes the responsive images path with tenant_id/media_uuid/', function () {
    $media = new Media(['tenant_id' => '019797f0-0000-7000-8000-00000000000a']);
    $media->uuid = '4b1f2e2a-1234-4a56-8abc-1234567890ab';

    $path = (new TenantPathGenerator)->getPathForResponsiveImages($media);

    expect($path)->toBe('019797f0-0000-7000-8000-00000000000a/4b1f2e2a-1234-4a56-8abc-1234567890ab/responsive-images/');
});
