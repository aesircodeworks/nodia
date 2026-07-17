<?php

namespace App\Orders\Enums;

/**
 * Percentage discounts store basis points in discount_value (1000 is
 * 10 percent); fixed-amount discounts store integer minor units and
 * require a currency on the row (stage-07 plan, Data model).
 */
enum PromoCodeDiscountType: string
{
    case Percentage = 'percentage';
    case FixedAmount = 'fixed_amount';
}
