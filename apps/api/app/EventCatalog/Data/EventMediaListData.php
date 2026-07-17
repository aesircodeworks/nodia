<?php

namespace App\EventCatalog\Data;

use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * GET /v1/events/{event}/media query parameters (stage-05c plan,
 * Endpoints: "Optional collection filter validated against the enum").
 * collection is nullable rather than Optional: an absent query parameter
 * means "no filter", a real, meaningful value the controller branches on,
 * not merely "the caller didn't send this key" (Spatie\LaravelData\Optional's
 * own use case).
 */
#[MapName(SnakeCaseMapper::class)]
final class EventMediaListData extends Data
{
    public function __construct(
        public ?string $collection = null,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'collection' => ['sometimes', 'string', Rule::in(['cover', 'gallery'])],
        ];
    }
}
