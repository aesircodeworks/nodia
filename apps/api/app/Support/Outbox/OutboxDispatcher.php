<?php

namespace App\Support\Outbox;

use App\Support\Outbox\Enums\OutboxDeliveryStatus;
use App\Support\Outbox\Jobs\ProcessOutboxDelivery;
use App\Support\Outbox\Models\OutboxDelivery;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * After-commit enqueue of one Horizon job per pending subscriber delivery
 * (system-design 9.2, stage-04 plan Worker access pattern). Jobs are
 * scheduled only after the producing transaction commits so a rollback
 * enqueues nothing. Sets last_enqueued_at under a tenant-scoped write
 * before dispatch so the sweeper grace window measures from a real
 * enqueue (stage-04 plan Data model, outbox_deliveries).
 *
 * Job payload is event id plus subscriber name: system-design 9.2 says
 * jobs carry only the event ID (no envelope/payload in Redis); the
 * subscriber name is the minimal multi-subscriber routing key so one
 * shared job class can target a single delivery row. See stage-04
 * execution journal task-07.
 */
final readonly class OutboxDispatcher
{
    public function __construct(private TenantTransaction $transactions) {}

    /**
     * @param  list<string>  $subscribers
     */
    public function dispatchAfterCommit(string $eventId, string $tenantId, array $subscribers): void
    {
        if ($subscribers === []) {
            return;
        }

        DB::afterCommit(function () use ($eventId, $tenantId, $subscribers): void {
            foreach ($subscribers as $subscriber) {
                $this->transactions->asTenant($tenantId, function () use ($eventId, $subscriber): void {
                    OutboxDelivery::query()
                        ->where('outbox_event_id', $eventId)
                        ->where('subscriber', $subscriber)
                        ->where('status', OutboxDeliveryStatus::Pending)
                        ->update(['last_enqueued_at' => Date::now()]);
                });

                ProcessOutboxDelivery::dispatch($eventId, $subscriber);
            }
        });
    }
}
