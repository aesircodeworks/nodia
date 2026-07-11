<?php

namespace App\Payments\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * POST /v1/storefront/orders/{order}/payments request body (stage-08a
 * plan, Endpoints). details carries method-specific fields the gateway
 * defines; for cards this is the gateway token from hosted fields,
 * never PAN data (system-design 7.5).
 */
#[TypeScript]
#[MapName(SnakeCaseMapper::class)]
class InitiatePaymentData extends Data
{
    /**
     * @param  array<string, mixed>|Optional  $details
     */
    public function __construct(
        public string $method,
        public array|Optional $details,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function detailsArray(): array
    {
        return $this->details instanceof Optional ? [] : $this->details;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'method' => ['required', 'string', 'max:64'],
            'details' => ['sometimes', 'array'],
        ];
    }
}
