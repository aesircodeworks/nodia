<?php

namespace App\Support\Outbox\Jobs;

use App\Support\Outbox\Models\OutboxDelivery;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OrderedConsumption;
use App\Support\Outbox\OrderedOutboxSubscriber;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

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
 *
 * OrderedOutboxSubscriber handlers additionally pass OrderedConsumption
 * before markProcessed: unready deliveries stay pending and the job is
 * released with ordered_defer_seconds backoff (sized under the sweeper
 * grace so stranded rows still age out). On the final attempt the job
 * returns without failing so the sweeper re-enqueues rather than the
 * delivery landing in failed_jobs (stage-04 ordered-helper risk).
 */
class ProcessOutboxDelivery implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /**
     * Enough attempts for ordered deferrals to wait on a predecessor or
     * the stability window without exhausting into failed_jobs under the
     * default ordered_defer_seconds. Horizon supervisor tries is overridden
     * by this job property.
     */
    public int $tries = 40;

    /**
     * Seconds between attempts; null keeps the queue default. Set from a
     * per-subscriber policy below.
     */
    public ?int $backoff = null;

    public function __construct(
        public readonly string $eventId,
        public readonly string $subscriber,
    ) {
        // Consumers with an externally mandated retry budget (stage-08a
        // plan Slice 9: SendOrderConfirmation retries 5 times with linear
        // 1-minute backoff per system-design 13) declare it in
        // config/outbox.php; everything else keeps the outbox-wide
        // defaults above.
        $policy = config("outbox.subscriber_retries.{$subscriber}");

        if (is_array($policy)) {
            $this->tries = (int) ($policy['tries'] ?? $this->tries);
            $this->backoff = isset($policy['backoff_seconds']) ? (int) $policy['backoff_seconds'] : $this->backoff;
        }
    }

    public function handle(
        TenantTransaction $transactions,
        SubscriberRegistry $subscribers,
        OrderedConsumption $ordered,
    ): void {
        $event = $transactions->asPlatform(
            fn (): ?OutboxEvent => OutboxEvent::query()->whereKey($this->eventId)->first(),
        );

        if ($event === null) {
            throw (new ModelNotFoundException)->setModel(OutboxEvent::class, [$this->eventId]);
        }

        $handler = $subscribers->handler($this->subscriber);
        $subscriber = $this->subscriber;

        $deferred = false;

        $transactions->asTenant($event->tenant_id, function () use ($event, $handler, $subscriber, $ordered, &$deferred): void {
            if ($handler instanceof OrderedOutboxSubscriber && ! $ordered->isReady($event, $subscriber)) {
                $deferred = true;

                return;
            }

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

        if ($deferred) {
            $this->deferOrdered();
        }
    }

    /**
     * Release with config backoff, or complete cleanly on the final attempt
     * so the pending delivery remains for the reconciliation sweeper.
     */
    private function deferOrdered(): void
    {
        if ($this->attempts() >= $this->tries) {
            return;
        }

        $this->release(config()->integer('outbox.ordered_defer_seconds'));
    }
}
