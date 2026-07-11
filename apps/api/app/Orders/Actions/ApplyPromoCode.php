<?php

namespace App\Orders\Actions;

use App\Orders\Data\AppliedPromoCode;
use App\Orders\Exceptions\PromoCodeCurrencyMismatchException;
use App\Orders\Exceptions\PromoCodeExhaustedException;
use App\Orders\Exceptions\PromoCodeInvalidException;
use App\Orders\Exceptions\PromoCodeNotActiveException;
use App\Orders\Models\PromoCode;
use App\Support\Money\Money;
use App\Support\Problems\ErrorCode;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Redeems a promo code inside the conversion transaction (stage-07
 * plan, Slice 5): evaluation first, then the atomic usage increment as
 * a conditional UPDATE checked by affected-row count, the same pattern
 * as inventory (system-design 8.3 notes). Zero rows means a concurrent
 * redemption took the last slot; the loser rolls back completely with
 * its hold intact. Usage is never released when an order later dies
 * unpaid; recovering exhausted codes from abandoned checkouts is an
 * explicitly flagged product decision (stage-07 plan, Risks).
 */
final class ApplyPromoCode
{
    public function __construct(private readonly EvaluatePromoCode $evaluate) {}

    public function __invoke(string $code, Money $subtotal): AppliedPromoCode
    {
        $evaluation = ($this->evaluate)($code, $subtotal);

        if (! $evaluation->valid) {
            throw match ($evaluation->reason) {
                ErrorCode::PromoCodeInvalid => PromoCodeInvalidException::forCode($code),
                ErrorCode::PromoCodeNotActive => PromoCodeNotActiveException::forCode($code),
                ErrorCode::PromoCodeExhausted => PromoCodeExhaustedException::forCode($code),
                ErrorCode::PromoCodeCurrencyMismatch => PromoCodeCurrencyMismatchException::forCode($code),
                default => new LogicException('Unmapped promo evaluation reason.'),
            };
        }

        $affected = PromoCode::query()
            ->whereKey($evaluation->promoCode->id)
            ->whereRaw('(usage_limit is null or usage_count < usage_limit)')
            ->update(['usage_count' => DB::raw('usage_count + 1')]);

        if ($affected !== 1) {
            throw PromoCodeExhaustedException::forCode($code);
        }

        return new AppliedPromoCode($evaluation->promoCode->id, $code, $evaluation->discount);
    }
}
