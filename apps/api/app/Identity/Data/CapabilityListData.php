<?php

namespace App\Identity\Data;

use App\Identity\Capability;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Wraps the capability registry in an object rather than returning a bare
 * JSON array: the Contract suite's schema strictness gate (ADR 019)
 * requires additionalProperties: false on every documented response
 * schema, which only an object schema can declare, and every other list
 * response in this API is already an object (a paginator envelope);
 * GET /v1/capabilities has no pagination metadata to carry, so data is
 * the only member.
 */
#[MapName(SnakeCaseMapper::class)]
class CapabilityListData extends Data
{
    /**
     * @param  list<CapabilityData>  $data
     */
    public function __construct(
        public array $data,
    ) {}

    public static function fromRegistry(): self
    {
        return new self(array_map(
            fn (Capability $capability): CapabilityData => CapabilityData::fromCapability($capability),
            Capability::cases(),
        ));
    }
}
