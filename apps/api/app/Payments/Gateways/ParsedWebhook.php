<?php

namespace App\Payments\Gateways;

final class ParsedWebhook
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $gatewayEventId,
        public readonly array $payload,
    ) {}
}
