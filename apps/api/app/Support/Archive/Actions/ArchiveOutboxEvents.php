<?php

namespace App\Support\Archive\Actions;

use App\Support\Archive\Enums\ArchiveSegmentSource;
use App\Support\Archive\Models\ArchiveSegment;
use App\Support\Outbox\Enums\OutboxDeliveryStatus;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The outbox archiver (stage-12 plan, Slice 4, task breakdown item 10;
 * system-design 9.1). Exports outbox_events rows older than
 * config('retention.outbox_archival_days') to one NDJSON object storage
 * segment, writes the archive_segments manifest row, and deletes the
 * source rows only once the uploaded object's checksum, read back from
 * storage, matches the checksum computed before upload. Manifests remain
 * incomplete until every per-tenant deletion commits; a later run first
 * verifies and recovers incomplete manifests. Deterministic object keys
 * and a unique source range make overlapping runs converge on one segment.
 *
 * Resolves the plan's open question on system-design 9.1's "archived ...
 * independent of delivery state": read as the archival *schedule* not
 * waiting on any delivery-completion signal, not as archiving over
 * undelivered work. A row with any pending outbox_deliveries row (any
 * subscriber, not just one) is never archived; hitting one while walking
 * a batch in ascending sequence order stops the whole batch there rather
 * than skipping past it, so every segment's [range_from, range_to] is a
 * true contiguous prefix with no live row inside it. A stalled pending
 * delivery this old blocking further archival is intentional: the
 * sweeper's own grace window is a minute and the retention window is
 * months, so that combination signals a bug worth surfacing, not
 * archiving over (plan Risks and open questions).
 *
 * Deletion mechanics differ from ArchiveActivityLog's single-transaction
 * shape because outbox_events and outbox_deliveries carry no platform-
 * write RLS policy (stage-04 plan; App\Support\Database\Rls::
 * applyTenantPolicies with no $platformWrite): the platform role can
 * SELECT them but never DELETE. The scan, upload, and manifest write run
 * in one platform-role transaction; verified rows then delete in one
 * nodia_app transaction per tenant present in the segment, iterating the
 * tenant IDs found while building it, deleting outbox_deliveries before
 * outbox_events in each to satisfy the foreign key. Both deletes go
 * through the query builder directly (DB::table), not the OutboxEvent
 * Eloquent model, so the model's own append-only guard (never triggered
 * by a query-builder mass delete, only by an individual model's own
 * update()/delete()) never fires on this path.
 */
final readonly class ArchiveOutboxEvents
{
    public function __construct(private TenantTransaction $transactions) {}

    public function __invoke(): int
    {
        $days = config()->integer('retention.outbox_archival_days');

        if ($days <= 0) {
            throw new RuntimeException(
                "retention.outbox_archival_days must be a positive number of days to run the outbox archiver, got {$days}.",
            );
        }

        $batchSize = config()->integer('retention.outbox_archive_batch_size');

        if ($batchSize <= 0) {
            throw new RuntimeException(
                "retention.outbox_archive_batch_size must be a positive number to run the outbox archiver, got {$batchSize}.",
            );
        }

        $recovered = $this->recoverIncompleteSegments();
        $cutoff = Date::now()->subDays($days);

        /** @var array{segment_id: string, ids_by_tenant: array<string, list<string>>}|null $batch */
        $batch = $this->transactions->asPlatform(function () use ($cutoff, $batchSize): ?array {
            $candidates = DB::table('outbox_events')
                ->where('occurred_at', '<', $cutoff)
                ->orderBy('sequence')
                ->limit($batchSize)
                ->get();

            if ($candidates->isEmpty()) {
                return null;
            }

            $pending = array_flip(
                DB::table('outbox_deliveries')
                    ->whereIn('outbox_event_id', $candidates->pluck('id'))
                    ->where('status', OutboxDeliveryStatus::Pending->value)
                    ->pluck('outbox_event_id')
                    ->all(),
            );

            $eligible = [];

            foreach ($candidates as $row) {
                if (isset($pending[$row->id])) {
                    // Stop at the first row with a pending delivery: keep
                    // the archived range a true contiguous prefix rather
                    // than skipping past it and leaving a hole.
                    break;
                }

                $eligible[] = $row;
            }

            if ($eligible === []) {
                return null;
            }

            $content = collect($eligible)
                ->map(fn (object $row): string => json_encode($this->serialize($row), JSON_THROW_ON_ERROR))
                ->implode("\n")."\n";

            $checksum = hash('sha256', $content);
            $first = $eligible[0];
            $last = $eligible[count($eligible) - 1];
            $rangeFrom = (string) $first->sequence;
            $rangeTo = (string) $last->sequence;
            $disk = config()->string('retention.archive_disk');
            $objectKey = sprintf(
                'archive-segments/outbox_events/%s-%s-%s.ndjson',
                $rangeFrom,
                $rangeTo,
                $checksum,
            );

            try {
                $storage = Storage::disk($disk);
                $storage->put($objectKey, $content);
                $uploaded = $storage->get($objectKey);
            } catch (Throwable $e) {
                throw new RuntimeException(
                    "The outbox archiver could not reach object storage; no row was archived or deleted: {$e->getMessage()}",
                    previous: $e,
                );
            }

            if ($uploaded === null || hash('sha256', $uploaded) !== $checksum) {
                throw new RuntimeException(
                    'The outbox archiver could not verify the uploaded segment checksum; no row was deleted.',
                );
            }

            $segmentId = (string) Str::uuid7();
            $now = Date::now();

            DB::table('archive_segments')->insertOrIgnore([
                'id' => $segmentId,
                'source' => ArchiveSegmentSource::OutboxEvents->value,
                'range_from' => $rangeFrom,
                'range_to' => $rangeTo,
                'object_key' => $objectKey,
                'row_count' => count($eligible),
                'checksum' => $checksum,
                'archived_at' => $now,
                'completed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $segment = ArchiveSegment::query()
                ->where('source', ArchiveSegmentSource::OutboxEvents)
                ->where('range_from', $rangeFrom)
                ->where('range_to', $rangeTo)
                ->firstOrFail();

            if ($segment->checksum !== $checksum
                || $segment->object_key !== $objectKey
                || $segment->row_count !== count($eligible)) {
                throw new RuntimeException(
                    "Archive range {$rangeFrom}-{$rangeTo} already exists with different content.",
                );
            }

            $byTenant = [];

            foreach ($eligible as $row) {
                $byTenant[$row->tenant_id][] = $row->id;
            }

            return ['segment_id' => $segment->id, 'ids_by_tenant' => $byTenant];
        });

        if ($batch === null) {
            return $recovered;
        }

        $archived = $this->deleteRows($batch['ids_by_tenant']);

        $this->completeSegment($batch['segment_id']);

        return $recovered + $archived;
    }

    private function recoverIncompleteSegments(): int
    {
        $segments = $this->transactions->asPlatform(
            fn () => ArchiveSegment::query()
                ->where('source', ArchiveSegmentSource::OutboxEvents)
                ->whereNull('completed_at')
                ->orderBy('range_from')
                ->get(),
        );

        $recovered = 0;

        foreach ($segments as $segment) {
            $idsByTenant = [];

            foreach ($this->verifiedRows($segment) as $row) {
                $idsByTenant[$row['tenant_id']][] = $row['id'];
            }

            $recovered += $this->deleteRows($idsByTenant);
            $this->completeSegment($segment->id);
        }

        return $recovered;
    }

    /**
     * @param  array<string, list<string>>  $idsByTenant
     */
    private function deleteRows(array $idsByTenant): int
    {
        $deleted = 0;

        foreach ($idsByTenant as $tenantId => $ids) {
            $deleted += $this->transactions->asTenant($tenantId, function () use ($ids): int {
                DB::table('outbox_deliveries')->whereIn('outbox_event_id', $ids)->delete();

                return DB::table('outbox_events')->whereIn('id', $ids)->delete();
            });
        }

        return $deleted;
    }

    private function completeSegment(string $segmentId): void
    {
        $this->transactions->asPlatform(
            fn () => ArchiveSegment::query()
                ->whereKey($segmentId)
                ->whereNull('completed_at')
                ->update(['completed_at' => Date::now(), 'updated_at' => Date::now()]),
        );
    }

    /**
     * @return list<array{id: string, tenant_id: string}>
     */
    private function verifiedRows(ArchiveSegment $segment): array
    {
        try {
            $content = Storage::disk(config()->string('retention.archive_disk'))->get($segment->object_key);
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Cannot recover archive segment {$segment->id}: {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($content === null || hash('sha256', $content) !== $segment->checksum) {
            throw new RuntimeException("Cannot recover archive segment {$segment->id}: checksum mismatch.");
        }

        $rows = [];

        foreach (explode("\n", trim($content)) as $line) {
            if ($line === '') {
                continue;
            }

            /** @var array{id: string, tenant_id: string} $row */
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $rows[] = $row;
        }

        if (count($rows) !== $segment->row_count) {
            throw new RuntimeException("Cannot recover archive segment {$segment->id}: row count mismatch.");
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(object $row): array
    {
        return [
            'id' => $row->id,
            'sequence' => (int) $row->sequence,
            'type' => $row->type,
            'tenant_id' => $row->tenant_id,
            'aggregate_type' => $row->aggregate_type,
            'aggregate_id' => $row->aggregate_id,
            'correlation_id' => $row->correlation_id,
            'occurred_at' => (string) $row->occurred_at,
            'payload' => json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR),
            'created_at' => (string) $row->created_at,
            'updated_at' => (string) $row->updated_at,
        ];
    }
}
