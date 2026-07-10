<?php

declare(strict_types=1);

namespace Tests\Support\Outbox;

use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OutboxSubscriber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Idempotent test subscriber for outbox delivery coverage (stage-04 plan
 * Slice 2 fixtures). Registers only in test setup via SubscriberRegistry
 * and never enters production routing. Effects are counted in-process and,
 * when the durable side table exists, also written so multi-process
 * concurrency workers can observe them.
 */
final class IdempotentTestSubscriber implements OutboxSubscriber
{
    public const string NAME = 'idempotent_test';

    public const string EFFECTS_TABLE = 'outbox_test_effects';

    private int $effects = 0;

    /** @var list<string> */
    private array $eventIds = [];

    public function handle(OutboxEvent $event): void
    {
        $this->effects++;
        $this->eventIds[] = $event->id;

        if (Schema::hasTable(self::EFFECTS_TABLE)) {
            DB::table(self::EFFECTS_TABLE)->insert([
                'id' => Str::uuid7()->toString(),
                'event_id' => $event->id,
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

    public static function durableEffectCount(?string $eventId = null): int
    {
        $query = DB::table(self::EFFECTS_TABLE);

        if ($eventId !== null) {
            $query->where('event_id', $eventId);
        }

        return $query->count();
    }
}
