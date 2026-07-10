<?php

namespace App\Support\Outbox;

use App\Support\Outbox\Enums\OutboxDeliveryStatus;
use App\Support\Outbox\Jobs\ProcessOutboxDelivery;
use App\Support\Outbox\Models\OutboxDelivery;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Reconciliation sweeper for stranded outbox deliveries (system-design
 * 9.2, stage-04 plan Slice 3). Cross-tenant platform SELECT finds pending
 * rows past the grace window whose events are older than the stability
 * window; each re-enqueue opens a tenant-scoped write for last_enqueued_at
 * then dispatches ProcessOutboxDelivery again. Processed rows are never
 * re-enqueued.
 */
final readonly class OutboxSweeper
{
    public function __construct(private TenantTransaction $transactions) {}

    /**
     * @return int Number of deliveries re-enqueued
     */
    public function sweep(): int
    {
        $stranded = $this->findStranded();

        $requeued = 0;

        foreach ($stranded as $delivery) {
            if ($this->reenqueue($delivery)) {
                $requeued++;
            }
        }

        return $requeued;
    }

    /**
     * @return Collection<int, OutboxDelivery>
     */
    private function findStranded(): Collection
    {
        $graceCutoff = Date::now()->subSeconds(config()->integer('outbox.sweeper_grace_seconds'));
        $stabilityCutoff = Date::now()->subSeconds(config()->integer('outbox.stability_window_seconds'));

        return $this->transactions->asPlatform(
            fn (): Collection => OutboxDelivery::query()
                ->select('outbox_deliveries.*')
                ->join('outbox_events', 'outbox_events.id', '=', 'outbox_deliveries.outbox_event_id')
                ->where('outbox_deliveries.status', OutboxDeliveryStatus::Pending)
                ->whereRaw(
                    'coalesce(outbox_deliveries.last_enqueued_at, outbox_deliveries.created_at) <= ?',
                    [$graceCutoff],
                )
                ->where('outbox_events.occurred_at', '<=', $stabilityCutoff)
                ->get(),
        );
    }

    private function reenqueue(OutboxDelivery $delivery): bool
    {
        $updated = $this->transactions->asTenant(
            $delivery->tenant_id,
            fn (): int => OutboxDelivery::query()
                ->whereKey($delivery->id)
                ->where('status', OutboxDeliveryStatus::Pending)
                ->update(['last_enqueued_at' => Date::now()]),
        );

        if ($updated === 0) {
            return false;
        }

        ProcessOutboxDelivery::dispatch($delivery->outbox_event_id, $delivery->subscriber);

        return true;
    }
}
