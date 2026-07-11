<?php

namespace App\Orders\Data;

use App\Support\Money\Money;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Internal result of ApplyPromoCode (stage-07 plan, Slice 5): the
 * redeemed promo id, the human-facing code for the wire shape, and the
 * priced discount.
 */
#[Hidden]
class AppliedPromoCode extends Data
{
    public function __construct(
        public string $promoCodeId,
        public string $code,
        public Money $discount,
    ) {}
}
