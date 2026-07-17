<?php

namespace App\Support\Archive\Models;

use App\Support\Archive\Enums\ArchiveSegmentSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The manifest row an archive-then-prune command writes after an
 * uploaded segment's checksum verifies and before it deletes the
 * source rows (stage-12 plan, Data model "archive_segments"; task
 * breakdown item 9). Lives under App\Support like
 * App\Support\Audit\Models\ActivityLogEntry and App\Support\Outbox\
 * Models: infrastructure with no single owning bounded context, since
 * segments span every tenant.
 *
 * @property string $id
 * @property ArchiveSegmentSource $source
 * @property string $range_from
 * @property string $range_to
 * @property string $object_key
 * @property int $row_count
 * @property string $checksum
 * @property Carbon $archived_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'source',
    'range_from',
    'range_to',
    'object_key',
    'row_count',
    'checksum',
    'archived_at',
    'completed_at',
])]
class ArchiveSegment extends Model
{
    use HasUuids;

    protected $table = 'archive_segments';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => ArchiveSegmentSource::class,
            'row_count' => 'integer',
            'archived_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
