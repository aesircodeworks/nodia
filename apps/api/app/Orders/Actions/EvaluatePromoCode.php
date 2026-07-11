<?php

namespace App\Orders\Actions;

use App\Orders\Data\PromoCodeEvaluation;
use App\Orders\Enums\PromoCodeDiscountType;
use App\Orders\Models\PromoCode;
use App\Support\Money\Money;
use App\Support\Problems\ErrorCode;
use Illuminate\Support\Facades\Date;

/**
 * The read-only promo evaluation shared by order creation and the
 * check preview (stage-07 plan, Slice 5): code existence, validity
 * window, remaining usage, currency match, and the discount math, all
 * in integer minor units. Percentage discounts are basis points floored
 * (floor(subtotal * value / 10000)); fixed discounts cap at the
 * subtotal, so discount_amount never exceeds subtotal_amount and
 * total_amount is never negative. Never increments usage_count; that is
 * ApplyPromoCode's conditional UPDATE.
 */
final class EvaluatePromoCode
{
    public function __invoke(string $code, Money $subtotal): PromoCodeEvaluation
    {
        $promo = PromoCode::query()->where('code', $code)->first();

        if ($promo === null) {
            return PromoCodeEvaluation::rejected(ErrorCode::PromoCodeInvalid);
        }

        $now = Date::now();

        if (($promo->valid_from !== null && $now->lessThan($promo->valid_from))
            || ($promo->valid_to !== null && $now->greaterThan($promo->valid_to))) {
            return PromoCodeEvaluation::rejected(ErrorCode::PromoCodeNotActive);
        }

        if ($promo->usage_limit !== null && $promo->usage_count >= $promo->usage_limit) {
            return PromoCodeEvaluation::rejected(ErrorCode::PromoCodeExhausted);
        }

        if ($promo->discount_type === PromoCodeDiscountType::FixedAmount && $promo->currency !== $subtotal->currency) {
            return PromoCodeEvaluation::rejected(ErrorCode::PromoCodeCurrencyMismatch);
        }

        $discount = match ($promo->discount_type) {
            PromoCodeDiscountType::Percentage => Money::of(
                intdiv($subtotal->amount * $promo->discount_value, 10000),
                $subtotal->currency,
            ),
            PromoCodeDiscountType::FixedAmount => Money::of(
                min($promo->discount_value, $subtotal->amount),
                $subtotal->currency,
            ),
        };

        return PromoCodeEvaluation::applies($promo, $discount);
    }
}
