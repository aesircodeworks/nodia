<?php

namespace App\Support\Outbox\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only outbox row (system-design 9.1, event-conventions envelope).
 * Rows are never updated or deleted by the application: the model blocks
 * both surfaces so later consumers and the recorder cannot mutate history.
 * Lives under App\Support\Outbox rather than a bounded context because the
 * outbox is shared infrastructure every context records into (system-design
 * 9.1, stage-04 plan).
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
