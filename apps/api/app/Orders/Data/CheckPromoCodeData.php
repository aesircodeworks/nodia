<?php

namespace App\Orders\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * POST /v1/storefront/promo-codes/check request body (stage-07 plan,
 * Endpoints): a read-only preview against the hold's priced subtotal.
 */
#[MapName(SnakeCaseMapper::class)]
class CheckPromoCodeData extends Data
{
    public function __construct(
        public string $code,
        public string $holdId,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
            'hold_id' => ['required', 'uuid'],
        ];
    }
}
