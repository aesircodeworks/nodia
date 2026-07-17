<?php

namespace App\Support\Outbox\Jobs;

use App\Support\Outbox\DetachedOutboxSubscriber;
use App\Support\Outbox\Enums\OutboxDeliveryStatus;
use App\Support\Outbox\KeyedOrderedOutboxSubscriber;
use App\Support\Outbox\Models\OutboxDelivery;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OrderedConsumption;
use App\Support\Outbox\OrderedOutboxSubscriber;
use App\Support\Outbox\ProjectionLock;
use App\Support\Outbox\ProjectionLockedSubscriber;
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
 *
 * ProjectionLockedSubscriber handlers (stage-11 plan, task 13) take
 * ProjectionLock shared immediately before markProcessed and release it
 * immediately after the effect runs; a reporting:rebuild pass holding
 * the same key exclusive makes the shared attempt fail, and the job
 * defers with the same ordered_defer_seconds backoff rather than
 * failing, so a rebuild and a live delivery on the same projection
 * always serialize instead of interleaving.
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
     * per-subscriber policy below; a list applies per-attempt backoff
     * (the refund executor's 1s, 5s, 15s budget).
     *
     * @var int|list<int>|null
     */
    public int|array|null $backoff = null;

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
            if (isset($policy['backoff_seconds'])) {
                $this->backoff = is_array($policy['backoff_seconds'])
                    ? array_map(intval(...), $policy['backoff_seconds'])
                    : (int) $policy['backoff_seconds'];
            }
        }
    }

    public function handle(
        TenantTransaction $transactions,
        SubscriberRegistry $subscribers,
        OrderedConsumption $ordered,
        ProjectionLock $lock,
    ): void {
        $event = $transactions->asPlatform(
            fn (): ?OutboxEvent => OutboxEvent::query()->whereKey($this->eventId)->first(),
        );

        if ($event === null) {
            throw (new ModelNotFoundException)->setModel(OutboxEvent::class, [$this->eventId]);
        }

        $handler = $subscribers->handler($this->subscriber);
        $subscriber = $this->subscriber;

        if ($handler instanceof DetachedOutboxSubscriber) {
            $this->handleDetached($transactions, $handler, $event);

            return;
        }

        $deferred = false;

        $transactions->asTenant($event->tenant_id, function () use ($event, $handler, $subscriber, $ordered, $lock, &$deferred): void {
            $keyPath = $handler instanceof KeyedOrderedOutboxSubscriber ? $handler->orderingKeyPayloadPath() : null;

            if ($handler instanceof OrderedOutboxSubscriber && ! $ordered->isReady($event, $subscriber, $keyPath)) {
                $deferred = true;

                return;
            }

            $lockKey = $handler instanceof ProjectionLockedSubscriber ? $handler->projectionLockKey() : null;

            if ($lockKey !== null && ! $lock->tryAcquireShared($lockKey)) {
                $deferred = true;

                return;
            }

            try {
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
            } finally {
                if ($lockKey !== null) {
                    $lock->releaseShared($lockKey);
                }
            }
        });

        if ($deferred) {
            $this->defer();
        }
    }

    /**
     * The DetachedOutboxSubscriber arc: the effect runs outside any
     * delivery transaction, so the network round trip inside the handler
     * never holds a connection or row lock. Idempotence inverts: the
     * handler's own conditional claim is the exactly-once guard, and the
     * delivery is marked processed only after the handler returns, so a
     * throw leaves it pending for the retry and the sweeper. Duplicate
     * workers may both invoke the handler; both find the claim taken and
     * no-op, then race harmlessly on markProcessed.
     */
    private function handleDetached(TenantTransaction $transactions, DetachedOutboxSubscriber $handler, OutboxEvent $event): void
    {
        $pending = $transactions->asTenant($event->tenant_id, fn (): bool => OutboxDelivery::query()
            ->where('outbox_event_id', $event->id)
            ->where('subscriber', $this->subscriber)
            ->where('status', OutboxDeliveryStatus::Pending)
            ->exists());

        if (! $pending) {
            return;
        }

        $handler->handle($event);

        $transactions->asTenant($event->tenant_id, function () use ($event): void {
            OutboxDelivery::query()
                ->where('outbox_event_id', $event->id)
                ->where('subscriber', $this->subscriber)
                ->firstOrFail()
                ->markProcessed();
        });
    }

    /**
     * Release with config backoff, or complete cleanly on the final attempt
     * so the pending delivery remains for the reconciliation sweeper. Used
     * for both ordered-predecessor deferrals and projection-lock
     * deferrals (stage-11 plan, task 13): the backoff and final-attempt
     * behavior are identical either way.
     */
    private function defer(): void
    {
        if ($this->attempts() >= $this->tries) {
            return;
        }

        $this->release(config()->integer('outbox.ordered_defer_seconds'));
    }
}
