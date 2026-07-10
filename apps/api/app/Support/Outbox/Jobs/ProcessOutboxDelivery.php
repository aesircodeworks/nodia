<?php

namespace App\Support\Outbox\Jobs;

use App\Support\Outbox\Models\OutboxDelivery;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers one outbox event to one registered subscriber (system-design
 * 9.2). Bootstrap loads the envelope on the cross-tenant platform role
 * (SELECT only; no staff audit, see stage-04 open questions) because the
 * job cannot set app.tenant_id before reading the row. Downstream work
 * opens a tenant-scoped transaction, conditionally marks the delivery
 * processed, then runs the subscriber effect: the row lock serializes
 * concurrent workers and a zero-row mark exits cleanly without retry
 * (stage-04 plan Domain events / Consumed).
 *
 * Payload is event id plus subscriber name (not the full envelope). The
 * subscriber name selects which delivery row this job owns when multiple
 * subscribers fan out from one event.
 */
class ProcessOutboxDelivery implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $eventId,
        public readonly string $subscriber,
    ) {}

    public function handle(TenantTransaction $transactions, SubscriberRegistry $subscribers): void
    {
        $event = $transactions->asPlatform(
            fn (): ?OutboxEvent => OutboxEvent::query()->whereKey($this->eventId)->first(),
        );

        if ($event === null) {
            throw (new ModelNotFoundException)->setModel(OutboxEvent::class, [$this->eventId]);
        }

        $handler = $subscribers->handler($this->subscriber);
        $subscriber = $this->subscriber;

        $transactions->asTenant($event->tenant_id, function () use ($event, $handler, $subscriber): void {
            $delivery = OutboxDelivery::query()
                ->where('outbox_event_id', $event->id)
                ->where('subscriber', $subscriber)
                ->firstOrFail();

            // Conditional mark BEFORE the effect so concurrent workers
            // serialize on the row lock and losers never run the effect.
            if (! $delivery->markProcessed()) {
                return;
            }

            $handler->handle($event);
        });
    }
}
