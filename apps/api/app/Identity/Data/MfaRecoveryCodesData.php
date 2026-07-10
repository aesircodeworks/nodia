<?php

namespace App\Identity\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class MfaRecoveryCodesData extends Data
{
    /**
     * @param  list<string>  $recoveryCodes
     */
    public function __construct(
        public array $recoveryCodes,
    ) {}
}
