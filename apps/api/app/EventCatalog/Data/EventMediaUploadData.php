<?php

namespace App\EventCatalog\Data;

use App\Support\Media\ImageMediaCollections;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * POST /v1/events/{event}/media request body (stage-05c plan, Endpoints:
 * "Multipart request bound to EventMediaUploadData: file (required, image
 * mime allowlist, size cap) and collection (required, enum cover or
 * gallery)"). The allowlist and size cap are enforced here rather than
 * left to medialibrary's own acceptsMimeTypes() rejection so a bad
 * request renders the standard request.validation_failed problem with an
 * errors map, matching every other validated request in this codebase,
 * instead of medialibrary's own FileUnacceptableForCollection exception.
 */
#[MapName(SnakeCaseMapper::class)]
final class EventMediaUploadData extends Data
{
    public function __construct(
        // Without this override, the TypeScript generator has no mapping
        // for Illuminate\Http\UploadedFile and emits `file: undefined`
        // (composer types:generate's own warning: "Tried replacing
        // reference to class Illuminate\Http\UploadedFile ... but it was
        // not found in the transformed types"). File is the DOM type a
        // browser client actually holds before building the FormData
        // this multipart endpoint expects.
        #[LiteralTypeScriptType('File')]
        public UploadedFile $file,
        public string $collection,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimetypes:'.implode(',', ImageMediaCollections::ACCEPTED_MIME_TYPES),
                'max:'.ImageMediaCollections::maxUploadKilobytes(),
            ],
            'collection' => ['required', 'string', Rule::in(['cover', 'gallery'])],
        ];
    }
}
