<?php

declare(strict_types=1);

use App\Support\Outbox\EventTypeRegistry;
use App\Support\Outbox\Jobs\ProcessOutboxDelivery;
use App\Support\Outbox\OrderedConsumption;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Outbox\FixtureDomainEvent;
use Tests\Support\Outbox\IdempotentTestSubscriber;
use Tests\Support\Outbox\OrderedTestSubscriber;

/**
 * Pest helpers for outbox delivery and the mandated duplicate-delivery
 * tests that every future consumer starts from (api-implementation-plan
 * Method section; stage-04 plan Slice 2 fixtures).
 */

/**
 * @param  list<string>|null  $eventTypes
 */
function registerIdempotentOutboxSubscriber(?array $eventTypes = null): IdempotentTestSubscriber
{
    $subscriber = new IdempotentTestSubscriber;
    $types = $eventTypes ?? [FixtureDomainEvent::TYPE];

    app(EventTypeRegistry::class)->register(FixtureDomainEvent::TYPE);
    app(SubscriberRegistry::class)->register(IdempotentTestSubscriber::NAME, $types, $subscriber);

    return $subscriber;
}

/**
 * @param  list<string>|null  $eventTypes
 */
function registerOrderedOutboxSubscriber(?array $eventTypes = null): OrderedTestSubscriber
{
    $subscriber = new OrderedTestSubscriber;
    $types = $eventTypes ?? [FixtureDomainEvent::TYPE];

    app(EventTypeRegistry::class)->register(FixtureDomainEvent::TYPE);
    app(SubscriberRegistry::class)->register(OrderedTestSubscriber::NAME, $types, $subscriber);

    return $subscriber;
}

function registerKeyedOrderedOutboxSubscriber(string $keyPath = 'aggregate_id'): Tests\Support\Outbox\KeyedOrderedTestSubscriber
{
    $subscriber = new Tests\Support\Outbox\KeyedOrderedTestSubscriber($keyPath);

    app(EventTypeRegistry::class)->register(FixtureDomainEvent::TYPE);
    app(SubscriberRegistry::class)->register(Tests\Support\Outbox\KeyedOrderedTestSubscriber::NAME, [FixtureDomainEvent::TYPE], $subscriber);

    return $subscriber;
}

function ensureOutboxTestEffectsTable(): void
{
    if (Schema::hasTable(IdempotentTestSubscriber::EFFECTS_TABLE)) {
        return;
    }

    Schema::create(IdempotentTestSubscriber::EFFECTS_TABLE, function (Blueprint $table): void {
        // bigserial PK records application order for ordered-consumption
        // concurrency assertions (stage-04 Slice 4).
        $table->id();
        $table->uuid('event_id');
        $table->unsignedBigInteger('event_sequence')->nullable();
        $table->string('subscriber');
    });

    // Workers run under nodia_app via TenantTransaction; grant so the
    // durable effect side channel is writable inside that posture.
    // Sequence/identity nextval needs USAGE for nodia_app inserts.
    $table = IdempotentTestSubscriber::EFFECTS_TABLE;
    DB::statement("grant select, insert, update, delete on {$table} to nodia_app, nodia_platform");
    DB::statement("grant usage, select on sequence {$table}_id_seq to nodia_app, nodia_platform");
}

function dropOutboxTestEffectsTable(): void
{
    Schema::dropIfExists(IdempotentTestSubscriber::EFFECTS_TABLE);
}

/**
 * Runs the delivery job twice for the same event and subscriber, the
 * mandated duplicate-delivery shape for future consumers.
 */
function processOutboxDeliveryTwice(string $eventId, string $subscriber = IdempotentTestSubscriber::NAME): void
{
    $job = new ProcessOutboxDelivery($eventId, $subscriber);
    $job->handle(
        app(TenantTransaction::class),
        app(SubscriberRegistry::class),
        app(OrderedConsumption::class),
    );
    $job->handle(
        app(TenantTransaction::class),
        app(SubscriberRegistry::class),
        app(OrderedConsumption::class),
    );
}

function forgetOutboxSubscriberRegistry(): void
{
    app()->forgetInstance(SubscriberRegistry::class);
}
