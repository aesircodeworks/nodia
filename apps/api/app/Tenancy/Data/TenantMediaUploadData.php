<?php

namespace App\Tenancy\Data;

use App\Support\Media\ImageMediaCollections;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * POST /v1/tenants/{tenant}/media request body (stage-05c plan, Endpoints:
 * "Body TenantMediaUploadData: file plus collection (enum logo)").
 * Mirrors App\EventCatalog\Data\EventMediaUploadData: the same mime
 * allowlist and size cap from App\Support\Media\ImageMediaCollections,
 * enforced here rather than left to medialibrary's own rejection so a bad
 * request renders the standard request.validation_failed problem with an
 * errors map. collection has exactly one allowed value (logo), unlike
 * the event upload's cover/gallery choice, since Tenant registers only
 * the one collection.
 */
#[MapName(SnakeCaseMapper::class)]
final class TenantMediaUploadData extends Data
{
    public function __construct(
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
            'collection' => ['required', 'string', Rule::in(['logo'])],
        ];
    }
}
