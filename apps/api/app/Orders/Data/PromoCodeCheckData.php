<?php

namespace App\Orders\Data;

use App\Support\Money\Money;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * POST /v1/storefront/promo-codes/check response shape (stage-07 plan,
 * Endpoints): valid, the priced discount when valid, and the stable
 * reason_code when not. Never increments usage_count.
 */
#[MapName(SnakeCaseMapper::class)]
class PromoCodeCheckData extends Data
{
    public function __construct(
        public bool $valid,
        public ?Money $discount,
        public ?string $reasonCode,
    ) {}

    public static function fromEvaluation(PromoCodeEvaluation $evaluation): self
    {
        return new self(
            $evaluation->valid,
            $evaluation->discount,
            $evaluation->reason?->value,
        );
    }
}
