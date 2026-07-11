<?php

namespace App\EventCatalog\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * GET /v1/storefront/events query parameters (stage-05c plan, Endpoints:
 * "Storefront search"). q is nullable rather than Optional: an absent q
 * means "no search, unchanged Stage 5a list behavior," a real, meaningful
 * value the controller branches on, mirroring EventMediaListData's own
 * precedent for collection.
 */
#[MapName(SnakeCaseMapper::class)]
final class StorefrontEventSearchQueryData extends Data
{
    public function __construct(
        public ?string $q = null,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'min:2', 'max:200'],
        ];
    }
}
