<?php

namespace App\Payments\Gateways;

final class FakeWebhookDelivery
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $body,
        public readonly array $headers,
    ) {}
}
