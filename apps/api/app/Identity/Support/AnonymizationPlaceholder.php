<?php

namespace App\Identity\Support;

use RuntimeException;

/**
 * Derives the deterministic, non-reversible, per-tenant-unique name and
 * email placeholder App\Identity\Actions\AnonymizeCustomer writes over a
 * customer's PII (stage-12 plan, Data model "No column change for
 * erasure"). Keyed HKDF over the application secret and the customer's own
 * id, mirroring App\Orders\Support\DerivedTicketSigningKeyProvider's own
 * precedent exactly: deterministic (the same customer id always derives
 * the same placeholder, so a replayed erasure is a no-op), non-reversible
 * (the digest is one-way and keyed on a secret never present in the
 * placeholder itself, so it cannot be inverted back to the customer id,
 * let alone the erased name or email it never saw), and unique (customer
 * ids are globally unique, so the derived email satisfies the per-tenant
 * customers (tenant_id, email) constraint forever).
 */
final readonly class AnonymizationPlaceholder
{
    private function __construct(
        public string $name,
        public string $email,
    ) {}

    public static function forCustomer(string $customerId): self
    {
        $appKey = (string) config('app.key');

        if (str_starts_with($appKey, 'base64:')) {
            $appKey = (string) base64_decode(substr($appKey, 7), true);
        }

        if ($appKey === '') {
            throw new RuntimeException('The application key is not set; customers cannot be anonymized.');
        }

        $token = bin2hex(hash_hkdf('sha256', $appKey, 16, 'nodia-anonymization-v1:'.$customerId));

        return new self(
            'Erased Customer '.substr($token, 0, 8),
            "erased+{$token}@erased.invalid",
        );
    }
}
