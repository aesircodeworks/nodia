<?php

namespace App\Orders\Data;

use App\Orders\Models\EventSigningKey;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Wraps the event's non-revoked signing keys in an object rather than a
 * bare JSON array (stage-09 plan, Endpoints "GET /v1/events/{event}/
 * signing-keys"): the same additionalProperties: false requirement
 * App\Identity\Data\CapabilityListData's own docblock explains, since
 * this is an unpaginated bounded collection with no pagination metadata
 * to carry either.
 */
#[MapName(SnakeCaseMapper::class)]
class SigningKeyListData extends Data
{
    /**
     * @param  list<SigningKeyData>  $data
     */
    public function __construct(
        public array $data,
    ) {}

    /**
     * @param  Collection<int, EventSigningKey>  $keys  already excludes revoked keys, per the controller's own query
     */
    public static function fromModels(Collection $keys): self
    {
        return new self($keys
            ->map(fn (EventSigningKey $key): SigningKeyData => SigningKeyData::fromModel($key))
            ->all());
    }
}
