<?php

namespace App\Support\Outbox;

use App\Support\Archive\Enums\ArchiveSegmentSource;
use App\Support\Archive\Models\ArchiveSegment;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;

/**
 * Rescans outbox_events in global sequence order past the stability window
 * to rebuild a projection (system-design 9.1, stage-04 plan Slice 5).
 * Filters by the subscriber's registered event types and optionally by an
 * inclusive starting sequence. Feeds each matching envelope to the
 * subscriber handler under the event's tenant scope; delivery rows are not
 * consulted or mutated. Consumer idempotence (by event id) makes replaying
 * an already-built projection safe.
 *
 * Archive-aware (stage-12 plan, Slice 4, task breakdown item 10): a row
 * the outbox archiver (App\Support\Archive\Actions\ArchiveOutboxEvents)
 * has already moved to an object storage segment no longer lives in
 * outbox_events, so eventsFor() also reads matching archive_segments rows
 * (source outbox_events), decodes their NDJSON lines, and merges the
 * result with the live rows before sorting by sequence. A history that
 * spans the archive boundary is visited exactly once per event, in the
 * same global sequence order it would have been in had nothing ever been
 * archived: archived rows are always well past the stability window (the
 * archival window is months, the stability window seconds), so no
 * separate stability filter applies to them.
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
     * Events eligible for replay: subscribed types only, ordered by
     * sequence ascending, optionally starting at fromSequence inclusive.
     * Archived rows (all types, all tenants) come from archivedEventsFor();
     * live rows past the stability window come from the platform SELECT
     * below. Platform SELECT covers every tenant; handlers run under each
     * event's tenant scope above.
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

        $archived = $this->archivedEventsFor($types, $fromSequence);

        $live = $this->transactions->asPlatform(
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

        return $archived
            ->concat($live)
            ->sortBy(fn (OutboxEvent $event): int => (int) $event->sequence)
            ->values();
    }

    /**
     * @param  list<string>  $types
     * @return Collection<int, OutboxEvent>
     */
    private function archivedEventsFor(array $types, ?int $fromSequence): Collection
    {
        $segments = $this->transactions->asPlatform(
            fn (): Collection => ArchiveSegment::query()
                ->where('source', ArchiveSegmentSource::OutboxEvents)
                ->get(),
        )->sortBy(fn (ArchiveSegment $segment): int => (int) $segment->range_from);

        if ($segments->isEmpty()) {
            return collect();
        }

        $disk = Storage::disk(config()->string('retention.archive_disk'));
        $events = collect();

        foreach ($segments as $segment) {
            if ($fromSequence !== null && (int) $segment->range_to < $fromSequence) {
                continue;
            }

            $content = $disk->get($segment->object_key);

            if ($content === null || trim($content) === '') {
                continue;
            }

            foreach (explode("\n", trim($content)) as $line) {
                if ($line === '') {
                    continue;
                }

                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

                if ($fromSequence !== null && (int) $decoded['sequence'] < $fromSequence) {
                    continue;
                }

                if (! in_array($decoded['type'], $types, true)) {
                    continue;
                }

                $events->push($this->hydrate($decoded));
            }
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hydrate(array $data): OutboxEvent
    {
        $event = new OutboxEvent([
            'id' => $data['id'],
            'type' => $data['type'],
            'tenant_id' => $data['tenant_id'],
            'aggregate_type' => $data['aggregate_type'],
            'aggregate_id' => $data['aggregate_id'],
            'correlation_id' => $data['correlation_id'],
            'occurred_at' => $data['occurred_at'],
            'payload' => $data['payload'],
        ]);
        $event->sequence = $data['sequence'];
        $event->exists = true;

        return $event;
    }
}
