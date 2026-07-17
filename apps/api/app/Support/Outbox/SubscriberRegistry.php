<?php

namespace App\Support\Outbox;

use LogicException;

/**
 * Static in-code routing from event type names to named subscribers
 * (system-design 9.2, stage-04 plan Slice 2). Production types register
 * from service providers; the registry never sniffs the environment.
 * Test-only subscribers register in test setup only.
 *
 * Bound as a container singleton: the map is application-level
 * configuration, not request state, so Octane reuse is safe.
 */
final class SubscriberRegistry
{
    /**
     * @var array<string, array{types: array<string, true>, handler: OutboxSubscriber}>
     */
    private array $subscribers = [];

    /**
     * @param  list<string>  $eventTypes
     */
    public function register(string $name, array $eventTypes, OutboxSubscriber $handler): void
    {
        if (isset($this->subscribers[$name])) {
            throw new LogicException("Subscriber [{$name}] is already registered.");
        }

        if ($eventTypes === []) {
            throw new LogicException("Subscriber [{$name}] must handle at least one event type.");
        }

        $types = [];

        foreach ($eventTypes as $type) {
            $types[$type] = true;
        }

        $this->subscribers[$name] = [
            'types' => $types,
            'handler' => $handler,
        ];
    }

    /**
     * Stable subscriber names interested in the given event type, in
     * registration order.
     *
     * @return list<string>
     */
    public function namesFor(string $eventType): array
    {
        $names = [];

        foreach ($this->subscribers as $name => $entry) {
            if (isset($entry['types'][$eventType])) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function handler(string $name): OutboxSubscriber
    {
        if (! isset($this->subscribers[$name])) {
            throw new LogicException("Subscriber [{$name}] is not registered.");
        }

        return $this->subscribers[$name]['handler'];
    }

    /**
     * Event type names this subscriber is registered for, in registration
     * order. Used by OutboxReplay to filter the rescan (system-design 9.1).
     *
     * @return list<string>
     */
    public function typesFor(string $name): array
    {
        if (! isset($this->subscribers[$name])) {
            throw new LogicException("Subscriber [{$name}] is not registered.");
        }

        return array_keys($this->subscribers[$name]['types']);
    }

    public function isRegistered(string $name): bool
    {
        return isset($this->subscribers[$name]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->subscribers);
    }
}
