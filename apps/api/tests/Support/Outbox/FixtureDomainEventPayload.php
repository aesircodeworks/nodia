<?php

declare(strict_types=1);

namespace Tests\Support\Outbox;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class FixtureDomainEventPayload extends Data
{
    public function __construct(
        public string $aggregateId,
        public string $displayName,
    ) {}
}
