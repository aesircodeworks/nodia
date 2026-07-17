<?php

namespace App\Orders\Data;

use App\Orders\Models\PromoCode;
use App\Support\Money\Money;
use App\Support\Problems\ErrorCode;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Internal result of EvaluatePromoCode (stage-07 plan, Slice 5): either
 * a priced discount for a live code, or the ErrorCode the caller maps
 * to a problem (order creation) or a reason_code string (the read-only
 * check preview).
 */
#[Hidden]
class PromoCodeEvaluation extends Data
{
    private function __construct(
        public bool $valid,
        public ?PromoCode $promoCode,
        public ?Money $discount,
        public ?ErrorCode $reason,
    ) {}

    public static function applies(PromoCode $promoCode, Money $discount): self
    {
        return new self(true, $promoCode, $discount, null);
    }

    public static function rejected(ErrorCode $reason): self
    {
        return new self(false, null, null, $reason);
    }
}
