<?php

namespace App\Payments\Support;

/**
 * Canonicalizes the initiation payload before hashing (stage-08a plan,
 * Endpoints "POST /v1/storefront/orders/{order}/payments"): associative
 * keys are sorted at every depth so two JSON encodings of the same
 * request hash identically, while list order stays significant.
 */
final class RequestHash
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function compute(string $method, array $details): string
    {
        return hash('sha256', json_encode([
            'method' => $method,
            'details' => self::canonicalize($details),
        ], JSON_UNESCAPED_SLASHES));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonicalize(...), $value);
    }
}
