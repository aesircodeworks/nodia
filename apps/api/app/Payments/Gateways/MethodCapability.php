<?php

namespace App\Payments\Gateways;

use App\Payments\Enums\PaymentMethodConfirmation;

final class MethodCapability
{
    public function __construct(
        public readonly string $method,
        public readonly PaymentMethodConfirmation $confirmation,
        public readonly ?int $confirmationWindowMinutes,
    ) {}

    public function isAsync(): bool
    {
        return $this->confirmation === PaymentMethodConfirmation::Async;
    }
}
