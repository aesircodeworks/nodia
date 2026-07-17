<?php

namespace App\Identity\Data;

use App\Identity\Capability;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class CapabilityData extends Data
{
    public function __construct(
        public string $name,
        public bool $isFinanciallyPrivileged,
    ) {}

    public static function fromCapability(Capability $capability): self
    {
        return new self($capability->value, $capability->isFinanciallyPrivileged());
    }
}
