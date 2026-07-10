<?php

namespace App\Identity\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class MfaEnrollmentData extends Data
{
    public function __construct(
        public string $secret,
        public string $otpauthUri,
    ) {}
}
