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

    /**
     * The delivery's headers in the server-parameter form a test request
     * takes, so a caller spreads them all in and never has to know which
     * headers the signing scheme happens to use.
     *
     * @return array<string, string>
     */
    public function serverHeaders(): array
    {
        $server = [];

        foreach ($this->headers as $name => $value) {
            $server['HTTP_'.str_replace('-', '_', strtoupper($name))] = $value;
        }

        return $server;
    }
}
