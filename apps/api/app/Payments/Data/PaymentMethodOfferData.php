<?php

namespace App\Payments\Data;

use App\Payments\Enums\PaymentMethodConfirmation;
use App\Payments\Gateways\MethodCapability;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One offered payment method (stage-08a plan, Endpoints "GET
 * /v1/storefront/orders/{order}/payment-methods").
 */
#[TypeScript]
#[MapName(SnakeCaseMapper::class)]
class PaymentMethodOfferData extends Data
{
    public function __construct(
        public string $method,
        public string $gateway,
        public PaymentMethodConfirmation $confirmation,
        public ?int $confirmationWindowMinutes,
    ) {}

    public static function fromCapability(string $gateway, MethodCapability $capability): self
    {
        return new self($capability->method, $gateway, $capability->confirmation, $capability->confirmationWindowMinutes);
    }
}
