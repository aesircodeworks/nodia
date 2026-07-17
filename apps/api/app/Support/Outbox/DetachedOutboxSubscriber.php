<?php

namespace App\Support\Outbox;

/**
 * A subscriber whose effect runs outside the delivery transaction
 * because it calls an external system: holding a database transaction
 * open across a network round trip couples connection and row-lock
 * lifetime to remote latency. The handler manages its own tenant
 * transactions and must be idempotent through a conditional state claim
 * of its own, because the delivery is marked processed only after the
 * handler returns; a throw leaves the delivery pending for the retry
 * and the sweeper. Incompatible with OrderedOutboxSubscriber and
 * ProjectionLockedSubscriber, whose checks live inside the delivery
 * transaction this interface opts out of.
 */
interface DetachedOutboxSubscriber extends OutboxSubscriber {}
