<?php

namespace App\Support\Outbox\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only outbox row (system-design 9.1, event-conventions envelope).
 * Rows are never updated, and never deleted before the retention window or
 * by anything but the archiver (stage-12 plan, Slice 4, task breakdown item
 * 10, relaxing the stage-04 plan's original "never deleted" reading): the
 * model blocks both surfaces for ordinary application code so consumers and
 * the recorder cannot mutate history. App\Support\Archive\Actions\
 * ArchiveOutboxEvents deletes verified rows past the window through the
 * query builder directly (DB::table('outbox_events')), which never fires
 * Eloquent model events, so the guard below is never in that path; it is
 * the sole approved bypass, exactly as it is for App\Support\Audit\Models\
 * ActivityLogEntry (Stage 3 plus task breakdown item 8's scoped delete
 * path). Lives under App\Support\Outbox rather than a bounded context
 * because the outbox is shared infrastructure every context records into
 * (system-design 9.1, stage-04 plan).
 *
 * @property string $id
 * @property int $sequence
 * @property string $type
 * @property string $tenant_id
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property string $correlation_id
 * @property Carbon $occurred_at
 * @property array<string, mixed> $payload
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'id',
    'type',
    'tenant_id',
    'aggregate_type',
    'aggregate_id',
    'correlation_id',
    'occurred_at',
    'payload',
])]
class OutboxEvent extends Model
{
    use HasUuids;

    protected $table = 'outbox_events';

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('OutboxEvent is append-only and cannot be updated.');
        });

        static::deleting(static function (): void {
            throw new LogicException('OutboxEvent is append-only and cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
