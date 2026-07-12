<?php

declare(strict_types=1);

namespace Tests\Support\Outbox;

use App\Support\Outbox\KeyedOrderedOutboxSubscriber;
use App\Support\Outbox\Models\OutboxEvent;

/**
 * Ordered test subscriber with a payload-derived ordering key for the
 * Stage 8b helper extension: events sharing the configured payload field
 * value order per that value across different envelope aggregates.
 */
final class KeyedOrderedTestSubscriber implements KeyedOrderedOutboxSubscriber
{
    public const string NAME = 'keyed_ordered_test';

    /** @var list<string> */
    private array $eventIds = [];

    public function __construct(private readonly string $keyPath = 'aggregate_id') {}

    public function orderingKeyPayloadPath(): string
    {
        return $this->keyPath;
    }

    public function handle(OutboxEvent $event): void
    {
        $this->eventIds[] = $event->id;
    }

    /**
     * @return list<string>
     */
    public function processedEventIds(): array
    {
        return $this->eventIds;
    }
}
