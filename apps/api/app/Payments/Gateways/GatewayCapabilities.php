<?php

namespace App\Payments\Gateways;

use InvalidArgumentException;

final class GatewayCapabilities
{
    /**
     * @param  list<MethodCapability>  $methods
     * @param  list<string>  $currencies
     */
    public function __construct(
        public readonly array $methods,
        public readonly array $currencies,
        public readonly bool $asyncConfirmation,
        public readonly bool $splitSupport,
    ) {}

    public function method(string $method): MethodCapability
    {
        foreach ($this->methods as $capability) {
            if ($capability->method === $method) {
                return $capability;
            }
        }

        throw new InvalidArgumentException("Unknown payment method [{$method}] for this gateway.");
    }

    public function supportsMethod(string $method): bool
    {
        foreach ($this->methods as $capability) {
            if ($capability->method === $method) {
                return true;
            }
        }

        return false;
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array($currency, $this->currencies, true);
    }
}
