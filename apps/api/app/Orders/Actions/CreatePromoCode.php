<?php

namespace App\Orders\Actions;

use App\Orders\Data\PromoCodeData;
use App\Orders\Data\UpsertPromoCodeData;
use App\Orders\Models\PromoCode;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Optional;

/**
 * Creates a promo code (stage-07 plan, Endpoints "POST
 * /v1/promo-codes"). (tenant_id, code) uniqueness rides the database's
 * unique index for the atomic guarantee and surfaces as the standard
 * validation failure shape.
 */
final class CreatePromoCode
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function __invoke(UpsertPromoCodeData $data): PromoCodeData
    {
        foreach (['code' => $data->code, 'discount_type' => $data->discountType, 'discount_value' => $data->discountValue] as $field => $value) {
            if ($value instanceof Optional) {
                throw ValidationException::withMessages([$field => ['The '.str_replace('_', ' ', $field).' field is required.']]);
            }
        }

        try {
            $promoCode = PromoCode::query()->create([
                'tenant_id' => $this->tenantContext->tenantId(),
                'code' => $data->code,
                'discount_type' => $data->discountType,
                'discount_value' => $data->discountValue,
                'currency' => $data->currency instanceof Optional ? null : $data->currency,
                'usage_limit' => $data->usageLimit instanceof Optional ? null : $data->usageLimit,
                'usage_count' => 0,
                'valid_from' => $data->validFrom instanceof Optional ? null : $data->validFrom,
                'valid_to' => $data->validTo instanceof Optional ? null : $data->validTo,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => ['A promo code with this code already exists.']]);
        }

        return PromoCodeData::fromModel($promoCode);
    }
}
