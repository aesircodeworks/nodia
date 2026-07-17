<?php

namespace App\Identity\Data;

/**
 * Internal contact facts for addressing customer email (stage-08a plan,
 * Slice 9); never serialized to the wire.
 */
final class CustomerContactData
{
    public function __construct(
        public readonly string $email,
        public readonly ?string $name,
        public readonly ?string $locale,
    ) {}
}
