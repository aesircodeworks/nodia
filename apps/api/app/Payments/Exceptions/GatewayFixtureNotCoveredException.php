<?php

namespace App\Payments\Exceptions;

use RuntimeException;

/**
 * Raised by the recorded-fixture harness (stage-08d plan, Slice 2) when a
 * request has no matching fixture, or a gateway slug has no fixture
 * directory at all. The harness never falls through to the network; a
 * missing fixture is a loud failure, not a silent live call.
 */
final class GatewayFixtureNotCoveredException extends RuntimeException
{
    public static function forGateway(string $gateway): self
    {
        return new self("No fixture directory exists for gateway [{$gateway}].");
    }

    public static function forRequest(string $gateway, string $method, string $url): self
    {
        return new self(
            "No recorded fixture covers {$method} {$url} for gateway [{$gateway}]. ".
            'Record it with `php artisan gateway:record-fixtures` or add a fixture by hand.'
        );
    }
}
