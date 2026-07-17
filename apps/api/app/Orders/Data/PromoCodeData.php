<?php

namespace App\Orders\Data;

use App\Orders\Models\PromoCode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * The staff promo code wire shape (stage-07 plan, Endpoints), including
 * usage_count so exhaustion is visible in the admin portal.
 */
#[MapName(SnakeCaseMapper::class)]
class PromoCodeData extends Data
{
    public function __construct(
        public string $id,
        public string $code,
        public string $discountType,
        public int $discountValue,
        public ?string $currency,
        public ?int $usageLimit,
        public int $usageCount,
        public ?string $validFrom,
        public ?string $validTo,
        public string $createdAt,
    ) {}

    public static function fromModel(PromoCode $promoCode): self
    {
        $format = fn (?DateTimeInterface $timestamp): ?string => $timestamp === null
            ? null
            : CarbonImmutable::instance($timestamp)->utc()->format('Y-m-d\TH:i:s\Z');

        return new self(
            $promoCode->id,
            $promoCode->code,
            $promoCode->discount_type->value,
            $promoCode->discount_value,
            $promoCode->currency,
            $promoCode->usage_limit,
            $promoCode->usage_count,
            $format($promoCode->valid_from),
            $format($promoCode->valid_to),
            $format($promoCode->created_at),
        );
    }
}
