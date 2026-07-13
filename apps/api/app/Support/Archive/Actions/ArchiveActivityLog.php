<?php

namespace App\Support\Archive\Actions;

use App\Support\Archive\Enums\ArchiveSegmentSource;
use App\Support\Archive\Models\ArchiveSegment;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The activity log archive-then-prune command's core (stage-12 plan,
 * Slice 3, task breakdown item 9; system-design 14.2 as amended by
 * task breakdown item 8, 14.3). Exports every activity_log row older
 * than config('retention.activity_log_days') to one NDJSON object
 * storage segment, writes the archive_segments manifest row, and
 * deletes the source rows only once the uploaded object's checksum,
 * read back from storage, matches the checksum computed before upload:
 * a disk that cannot be reached, or a checksum that fails to verify,
 * leaves every source row in place and writes no manifest row
 * (archive-then-delete, never delete-then-archive).
 *
 * Runs entirely under the platform role (App\Support\Tenancy\
 * TenantTransaction::asPlatform): the read uses the existing
 * cross-tenant activity_log_platform_read policy (Stage 3), the insert
 * uses the same Rls::grantUnscoped grant every archive_segments write
 * shares, and the delete sets app.activity_log_prune_cutoff to the
 * exact cutoff the read already used (task breakdown item 8's own
 * activity_log_platform_delete policy), so the read and the delete
 * operate on the identical row set inside one transaction. No
 * per-tenant loop is needed, unlike the outbox archiver's own deletion
 * mechanics (Slice 4): this table carries no meaningful tenant
 * boundary for a retention sweep to respect (its own migration
 * docblock: no foreign key to tenants, an audit trail deliberately
 * outlives the tenant row it describes).
 */
final readonly class ArchiveActivityLog
{
    public function __construct(private TenantTransaction $transactions) {}

    public function __invoke(): int
    {
        $days = config()->integer('retention.activity_log_days');

        if ($days <= 0) {
            throw new RuntimeException(
                "retention.activity_log_days must be a positive number of days to run the activity log archiver, got {$days}.",
            );
        }

        $cutoff = Date::now()->subDays($days);

        return $this->transactions->asPlatform(function () use ($cutoff): int {
            $rows = DB::table('activity_log')
                ->where('created_at', '<', $cutoff)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            if ($rows->isEmpty()) {
                return 0;
            }

            $content = $rows
                ->map(fn (object $row): string => json_encode($this->serialize($row), JSON_THROW_ON_ERROR))
                ->implode("\n")."\n";

            $checksum = hash('sha256', $content);
            $disk = config()->string('retention.archive_disk');
            $objectKey = sprintf('archive-segments/activity_log/%s.ndjson', Str::uuid7());

            try {
                $storage = Storage::disk($disk);
                $storage->put($objectKey, $content);
                $uploaded = $storage->get($objectKey);
            } catch (Throwable $e) {
                throw new RuntimeException(
                    "The activity log archiver could not reach object storage; no row was archived or deleted: {$e->getMessage()}",
                    previous: $e,
                );
            }

            if ($uploaded === null || hash('sha256', $uploaded) !== $checksum) {
                throw new RuntimeException(
                    'The activity log archiver could not verify the uploaded segment checksum; no row was deleted.',
                );
            }

            ArchiveSegment::create([
                'source' => ArchiveSegmentSource::ActivityLog,
                'range_from' => (string) $rows->first()->created_at,
                'range_to' => (string) $rows->last()->created_at,
                'object_key' => $objectKey,
                'row_count' => $rows->count(),
                'checksum' => $checksum,
                'archived_at' => Date::now(),
            ]);

            DB::selectOne('select set_config(?, ?, true)', [
                'app.activity_log_prune_cutoff',
                $cutoff->toIso8601String(),
            ]);

            return DB::table('activity_log')
                ->where('created_at', '<', $cutoff)
                ->delete();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(object $row): array
    {
        return [
            'id' => $row->id,
            'tenant_id' => $row->tenant_id,
            'log_name' => $row->log_name,
            'description' => $row->description,
            'subject_type' => $row->subject_type,
            'subject_id' => $row->subject_id,
            'event' => $row->event,
            'causer_type' => $row->causer_type,
            'causer_id' => $row->causer_id,
            'attribute_changes' => $row->attribute_changes !== null
                ? json_decode((string) $row->attribute_changes, true)
                : null,
            'properties' => $row->properties !== null
                ? json_decode((string) $row->properties, true)
                : null,
            'created_at' => (string) $row->created_at,
            'updated_at' => (string) $row->updated_at,
        ];
    }
}
