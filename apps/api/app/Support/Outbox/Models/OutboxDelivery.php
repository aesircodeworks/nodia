<?php

namespace App\Support\Outbox\Models;

use App\Support\Outbox\Enums\OutboxDeliveryStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * Per-subscriber progress row for one outbox event (system-design 9.2,
 * stage-04 plan Data model). Created pending in the producing transaction;
 * marked processed by a conditional UPDATE checked by affected-row count
 * so duplicate delivery is idempotent (event-conventions). Lives under
 * App\Support\Outbox as shared infrastructure, not a bounded context.
 *
 * @property string $id
 * @property string $outbox_event_id
 * @property string $tenant_id
 * @property string $subscriber
 * @property OutboxDeliveryStatus $status
 * @property Carbon|null $processed_at
 * @property Carbon|null $last_enqueued_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'outbox_event_id',
    'tenant_id',
    'subscriber',
    'status',
    'processed_at',
    'last_enqueued_at',
])]
class OutboxDelivery extends Model
{
    use HasUuids;

    protected $table = 'outbox_deliveries';

    /**
     * Transition this delivery from pending to processed via a conditional
     * UPDATE checked by affected-row count. Zero rows means another worker
     * already processed it; that is normal, not an error (event-conventions).
     */
    public function markProcessed(): bool
    {
        $affected = static::query()
            ->whereKey($this->getKey())
            ->where('status', OutboxDeliveryStatus::Pending)
            ->update([
                'status' => OutboxDeliveryStatus::Processed,
                'processed_at' => Date::now(),
            ]);

        if ($affected > 0) {
            $this->refresh();
        }

        return $affected > 0;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OutboxDeliveryStatus::class,
            'processed_at' => 'datetime',
            'last_enqueued_at' => 'datetime',
        ];
    }
}
