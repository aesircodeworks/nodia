<?php

namespace App\Support\Outbox;

/**
 * Allowed domain event type names for outbox recording. Production types
 * register from the owning context's service provider (type-name strings
 * only, so Support never imports context event classes). Test fixtures
 * register additional names in test setup; the registry never sniffs the
 * environment (stage-04 plan, Registry).
 *
 * Bound as a container singleton: the set is application-level configuration,
 * not request state, so Octane reuse is safe.
 */
final class EventTypeRegistry
{
    /** @var array<string, true> */
    private array $types = [];

    public function register(string $type): void
    {
        $this->types[$type] = true;
    }

    public function contains(string $type): bool
    {
        return isset($this->types[$type]);
    }
}
