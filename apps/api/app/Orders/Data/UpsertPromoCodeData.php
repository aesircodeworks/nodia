<?php

namespace App\Orders\Data;

use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

/**
 * POST and PATCH /v1/promo-codes request body (stage-07 plan,
 * Endpoints). Every field is Optional so PATCH stays partial; the POST
 * route adds its required fields through the controller-level rules
 * below. The currency-by-type invariant mirrors the promo_codes CHECK:
 * percentage codes carry no currency, fixed_amount codes must carry
 * one. (tenant_id, code) uniqueness is left to the database's own
 * unique index for the atomic guarantee and surfaced as a validation
 * failure by the controller.
 */
#[MapName(SnakeCaseMapper::class)]
class UpsertPromoCodeData extends Data
{
    public function __construct(
        public string|Optional $code,
        public string|Optional $discountType,
        public int|Optional $discountValue,
        public string|Optional|null $currency,
        public int|Optional|null $usageLimit,
        public string|Optional|null $validFrom,
        public string|Optional|null $validTo,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:64'],
            'discount_type' => ['sometimes', Rule::in(['percentage', 'fixed_amount'])],
            'discount_value' => ['sometimes', 'integer', 'min:1'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3', 'required_if:discount_type,fixed_amount', 'prohibited_if:discount_type,percentage'],
            'usage_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
