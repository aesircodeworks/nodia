<?php

namespace App\Support\Correlation;

use Illuminate\Support\Str;

/**
 * Request-scoped holder for the current correlation ID. Octane reuses
 * workers across requests, so this value must never live in static or
 * singleton state; the class is bound scoped() in the container so Octane
 * resets it between requests. Middleware assigns the inbound or generated
 * header value; CLI and other non-HTTP contexts generate a UUIDv7 on first
 * read and keep it for the rest of the scope.
 */
final class CorrelationId
{
    private ?string $value = null;

    public function set(string $value): void
    {
        $this->value = $value;
    }

    public function get(): string
    {
        return $this->value ??= Str::uuid7()->toString();
    }

    public function has(): bool
    {
        return $this->value !== null;
    }
}
