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
 * storage, matches the checksum computed before upload: a disk that
 * cannot be reached, or a checksum that fails to verify, leaves every
 * source row in place and writes no manifest row (archive-then-delete,
 * never delete-then-archive, ArchiveActivityLog's own precedent from
 * task breakdown item 9).
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

        $cutoff = Date::now()->subDays($days);

        /** @var array<string, list<string>>|null $idsByTenant */
        $idsByTenant = $this->transactions->asPlatform(function () use ($cutoff, $batchSize): ?array {
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
            $disk = config()->string('retention.archive_disk');
            $objectKey = sprintf('archive-segments/outbox_events/%s.ndjson', Str::uuid7());

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

            $first = $eligible[0];
            $last = $eligible[count($eligible) - 1];

            ArchiveSegment::create([
                'source' => ArchiveSegmentSource::OutboxEvents,
                'range_from' => (string) $first->sequence,
                'range_to' => (string) $last->sequence,
                'object_key' => $objectKey,
                'row_count' => count($eligible),
                'checksum' => $checksum,
                'archived_at' => Date::now(),
            ]);

            $byTenant = [];

            foreach ($eligible as $row) {
                $byTenant[$row->tenant_id][] = $row->id;
            }

            return $byTenant;
        });

        if ($idsByTenant === null) {
            return 0;
        }

        $archived = 0;

        foreach ($idsByTenant as $tenantId => $ids) {
            $this->transactions->asTenant($tenantId, function () use ($ids): void {
                DB::table('outbox_deliveries')->whereIn('outbox_event_id', $ids)->delete();
                DB::table('outbox_events')->whereIn('id', $ids)->delete();
            });

            $archived += count($ids);
        }

        return $archived;
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
