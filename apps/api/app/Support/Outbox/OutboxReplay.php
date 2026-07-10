<?php

namespace App\Support\Outbox;

use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Rescans outbox_events in global sequence order past the stability window
 * to rebuild a projection (system-design 9.1, stage-04 plan Slice 5).
 * Filters by the subscriber's registered event types and optionally by an
 * inclusive starting sequence. Feeds each matching envelope to the
 * subscriber handler under the event's tenant scope; delivery rows are not
 * consulted or mutated. Consumer idempotence (by event id) makes replaying
 * an already-built projection safe.
 */
final readonly class OutboxReplay
{
    public function __construct(
        private TenantTransaction $transactions,
        private SubscriberRegistry $subscribers,
    ) {}

    /**
     * @return int Number of events fed to the handler
     */
    public function replay(string $subscriber, ?int $fromSequence = null): int
    {
        $handler = $this->subscribers->handler($subscriber);
        $types = $this->subscribers->typesFor($subscriber);
        $events = $this->eventsFor($types, $fromSequence);

        $count = 0;

        foreach ($events as $event) {
            $this->transactions->asTenant(
                $event->tenant_id,
                static function () use ($handler, $event): void {
                    $handler->handle($event);
                },
            );
            $count++;
        }

        return $count;
    }

    /**
     * Events eligible for replay: subscribed types only, occurred_at past
     * the stability window, ordered by sequence ascending, optionally
     * starting at fromSequence inclusive. Platform SELECT covers every
     * tenant; handlers run under each event's tenant scope above.
     *
     * @param  list<string>  $types
     * @return Collection<int, OutboxEvent>
     */
    public function eventsFor(array $types, ?int $fromSequence = null): Collection
    {
        if ($types === []) {
            return collect();
        }

        $stabilityCutoff = Date::now()->subSeconds(config()->integer('outbox.stability_window_seconds'));

        return $this->transactions->asPlatform(
            function () use ($types, $fromSequence, $stabilityCutoff): Collection {
                $query = OutboxEvent::query()
                    ->whereIn('type', $types)
                    ->where('occurred_at', '<=', $stabilityCutoff)
                    ->orderBy('sequence');

                if ($fromSequence !== null) {
                    $query->where('sequence', '>=', $fromSequence);
                }

                return $query->get();
            },
        );
    }
}
