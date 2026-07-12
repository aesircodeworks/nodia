<?php

namespace App\Payments\Gateways;

use App\Payments\Exceptions\GatewayUnknownException;

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
     * Resolves a gateway slug to its bound adapter, or fails with the
     * typed error every caller already threw by hand at the ?? get()
     * call site (stage-08d plan, Slice 1); this centralizes that.
     *
     * @throws GatewayUnknownException when no adapter is bound to the slug
     */
    public function resolve(string $identifier): GatewayAdapter
    {
        return $this->adapters[$identifier] ?? throw GatewayUnknownException::forGateway($identifier);
    }

    /**
     * @return array<string, GatewayAdapter>
     */
    public function all(): array
    {
        return $this->adapters;
    }
}
