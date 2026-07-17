<?php

namespace App\Payments\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

/**
 * Per-gateway circuit breaker (stage-08a plan, Slice 8; system-design
 * 13): closed, open, half-open. State lives in the cache, not a table:
 * it is not a system of record, and a flush simply closes breakers,
 * which fails safe into normal error handling. An open breaker removes
 * the gateway's methods from the offer instead of degrading the whole
 * checkout; after the cooldown the breaker half-opens, a probe request
 * is allowed through, and its outcome closes or reopens the breaker.
 * Timestamps are stored as values and compared against Date::now()
 * rather than relying on cache TTLs, so the fake clock governs tests.
 */
final class CircuitBreaker
{
    public function isOpen(string $gateway): bool
    {
        $openUntil = Cache::get($this->key($gateway, 'open_until'));

        return $openUntil !== null && Date::now()->getTimestamp() < (int) $openUntil;
    }

    public function allowsRequest(string $gateway): bool
    {
        return ! $this->isOpen($gateway);
    }

    public function recordFailure(string $gateway): void
    {
        // A failure while half-open (the cooldown elapsed but the breaker
        // was never closed by a success) reopens immediately: the probe
        // answered.
        if (Cache::get($this->key($gateway, 'open_until')) !== null) {
            $this->open($gateway);

            return;
        }

        $failures = (int) Cache::increment($this->key($gateway, 'failures'));

        if ($failures >= (int) config('payments.circuit_breaker.failure_threshold')) {
            $this->open($gateway);
        }
    }

    public function recordSuccess(string $gateway): void
    {
        Cache::forget($this->key($gateway, 'failures'));
        Cache::forget($this->key($gateway, 'open_until'));
    }

    public function retryAfterSeconds(string $gateway): int
    {
        $openUntil = (int) Cache::get($this->key($gateway, 'open_until'), 0);

        return max(1, $openUntil - Date::now()->getTimestamp());
    }

    private function open(string $gateway): void
    {
        Cache::forget($this->key($gateway, 'failures'));
        Cache::put(
            $this->key($gateway, 'open_until'),
            Date::now()->getTimestamp() + (int) config('payments.circuit_breaker.cooldown_seconds'),
        );
    }

    private function key(string $gateway, string $suffix): string
    {
        return "payments:breaker:{$gateway}:{$suffix}";
    }
}
