<?php

namespace App\Orders\Actions;

use App\Orders\Data\PromoCodeData;
use App\Orders\Data\UpsertPromoCodeData;
use App\Orders\Enums\PromoCodeDiscountType;
use App\Orders\Exceptions\PromoCodeImmutableFieldException;
use App\Orders\Models\PromoCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Optional;

/**
 * Partially updates a promo code (stage-07 plan, Endpoints "PATCH
 * /v1/promo-codes/{promo_code}"): code, discount_type, discount_value,
 * and currency lock once usage_count > 0 (sending the unchanged value
 * back stays accepted); the validity windows remain editable, so
 * deactivation is setting valid_to.
 */
final class UpdatePromoCode
{
    public function __invoke(PromoCode $promoCode, UpsertPromoCodeData $data): PromoCodeData
    {
        $changes = [];

        $locked = [
            'code' => [$data->code, $promoCode->code],
            'discount_type' => [
                $data->discountType instanceof Optional ? $data->discountType : PromoCodeDiscountType::from($data->discountType),
                $promoCode->discount_type,
            ],
            'discount_value' => [$data->discountValue, $promoCode->discount_value],
            'currency' => [$data->currency, $promoCode->currency],
        ];

        foreach ($locked as $field => [$incoming, $current]) {
            if ($incoming instanceof Optional || $incoming === $current) {
                continue;
            }

            if ($promoCode->usage_count > 0) {
                throw PromoCodeImmutableFieldException::forField($field);
            }

            $changes[$field] = $incoming;
        }

        foreach (['usage_limit' => $data->usageLimit, 'valid_from' => $data->validFrom, 'valid_to' => $data->validTo] as $field => $value) {
            if (! $value instanceof Optional) {
                $changes[$field] = $value;
            }
        }

        if ($changes !== []) {
            try {
                $promoCode->update($changes);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['code' => ['A promo code with this code already exists.']]);
            }
        }

        return PromoCodeData::fromModel($promoCode->refresh());
    }
}
