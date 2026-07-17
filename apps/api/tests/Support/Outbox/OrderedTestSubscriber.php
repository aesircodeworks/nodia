<?php

declare(strict_types=1);

namespace Tests\Support\Outbox;

use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OrderedOutboxSubscriber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ordered test subscriber for Slice 4 coverage. Implements
 * OrderedOutboxSubscriber so ProcessOutboxDelivery applies the
 * OrderedConsumption gate. Registers only in test setup.
 */
final class OrderedTestSubscriber implements OrderedOutboxSubscriber
{
    public const string NAME = 'ordered_test';

    private int $effects = 0;

    /** @var list<string> */
    private array $eventIds = [];

    /** @var list<int> */
    private array $sequences = [];

    public function handle(OutboxEvent $event): void
    {
        $this->effects++;
        $this->eventIds[] = $event->id;
        $this->sequences[] = (int) $event->sequence;

        if (Schema::hasTable(IdempotentTestSubscriber::EFFECTS_TABLE)) {
            DB::table(IdempotentTestSubscriber::EFFECTS_TABLE)->insert([
                'event_id' => $event->id,
                'event_sequence' => $event->sequence,
                'subscriber' => self::NAME,
            ]);
        }
    }

    public function effectCount(): int
    {
        return $this->effects;
    }

    /**
     * @return list<string>
     */
    public function processedEventIds(): array
    {
        return $this->eventIds;
    }

    /**
     * @return list<int>
     */
    public function processedSequences(): array
    {
        return $this->sequences;
    }

    /**
     * Application order of applied event sequences from the durable side
     * channel (multi-process concurrency).
     *
     * @return list<int>
     */
    public static function durableAppliedSequences(): array
    {
        return DB::table(IdempotentTestSubscriber::EFFECTS_TABLE)
            ->where('subscriber', self::NAME)
            ->orderBy('id')
            ->pluck('event_sequence')
            ->map(static fn ($sequence): int => (int) $sequence)
            ->all();
    }
}
