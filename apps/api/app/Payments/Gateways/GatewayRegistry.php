<?php

namespace App\Payments\Gateways;

final class GatewayRegistry
{
    /**
     * @param  array<string, GatewayAdapter>  $adapters
     */
    public function __construct(private readonly array $adapters) {}

    public function get(string $identifier): ?GatewayAdapter
    {
        return $this->adapters[$identifier] ?? null;
    }

    /**
     * @return array<string, GatewayAdapter>
     */
    public function all(): array
    {
        return $this->adapters;
    }
}
