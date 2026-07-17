<?php

declare(strict_types=1);

use App\Support\Outbox\Enums\OutboxDeliveryStatus;
use App\Support\Outbox\EventTypeRegistry;
use App\Support\Outbox\Models\OutboxDelivery;
use App\Support\Outbox\OutboxRecorder;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\Outbox\FixtureDomainEvent;
use Tests\Support\Outbox\FixtureDomainEventPayload;
use Tests\Support\Outbox\IdempotentTestSubscriber;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-04 Slice 2 / exit criterion 2: end-to-end delivery via a real Redis
 * queue and a single queue:work iteration against real PostgreSQL. phpunit
 * defaults QUEUE_CONNECTION=sync; this suite alone switches the queue
 * connection to redis for the duration of each test.
 */

const OUTBOX_REDIS_E2E_QUEUE = 'outbox-redis-e2e';

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    // Require Redis: CI always provides it (api.yml redis:8-alpine service).
    // Prefer a hard failure over a skip so a missing Redis is visible.
    try {
        $pong = Redis::connection()->ping();
        if ($pong !== true && $pong !== 'PONG' && $pong !== 1) {
            throw new RuntimeException('unexpected PING response: '.var_export($pong, true));
        }
    } catch (Throwable $e) {
        throw new RuntimeException(
            'Outbox Redis e2e requires a reachable Redis at REDIS_HOST/REDIS_PORT (CI provides redis:8-alpine). '.$e->getMessage(),
            previous: $e,
        );
    }

    app()->forgetInstance(SubscriberRegistry::class);

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    app(EventTypeRegistry::class)->register(FixtureDomainEvent::TYPE);

    // Isolate from other suites and any local Horizon worker on "default".
    config()->set('queue.default', 'redis');
    config()->set('queue.connections.redis.queue', OUTBOX_REDIS_E2E_QUEUE);

    clearOutboxRedisE2eQueue();
});

afterEach(function (): void {
    clearOutboxRedisE2eQueue();

    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('outbox_deliveries')->where('tenant_id', $this->tenantId)->delete();
        DB::table('outbox_events')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    app()->forgetInstance(SubscriberRegistry::class);
    app()->forgetScopedInstances();

    // Restore phpunit default so later tests in the same process stay sync.
    config()->set('queue.default', 'sync');
    config()->set('queue.connections.redis.queue', env('REDIS_QUEUE', 'default'));
});

function clearOutboxRedisE2eQueue(): void
{
    $redis = Redis::connection();
    $queue = OUTBOX_REDIS_E2E_QUEUE;

    // Laravel RedisQueue key layout (prefix applied by the connection).
    foreach ([
        "queues:{$queue}",
        "queues:{$queue}:notify",
        "queues:{$queue}:delayed",
        "queues:{$queue}:reserved",
        "queues:{$queue}:reserved:notify",
    ] as $key) {
        $redis->del($key);
    }
}

function outboxRedisE2eQueueSize(): int
{
    return (int) Redis::connection()->llen('queues:'.OUTBOX_REDIS_E2E_QUEUE);
}

it('delivers via real Redis queue and queue:work once against PostgreSQL', function () {
    $subscriber = registerIdempotentOutboxSubscriber();

    $aggregateId = Str::uuid7()->toString();
    $event = new FixtureDomainEvent(
        tenantId: $this->tenantId,
        aggregateId: $aggregateId,
        payload: new FixtureDomainEventPayload($aggregateId, 'Redis E2E Fixture'),
    );

    $recorded = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(OutboxRecorder::class)->record($event),
    );

    // after-commit must have pushed a real Redis job (not sync-inline).
    expect(outboxRedisE2eQueueSize())->toBe(1)
        ->and($subscriber->effectCount())->toBe(0);

    $pending = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxDelivery::query()
            ->where('outbox_event_id', $recorded->id)
            ->where('subscriber', IdempotentTestSubscriber::NAME)
            ->firstOrFail(),
    );

    expect($pending->status)->toBe(OutboxDeliveryStatus::Pending);

    $exit = Artisan::call('queue:work', [
        'connection' => 'redis',
        '--queue' => OUTBOX_REDIS_E2E_QUEUE,
        '--once' => true,
        '--sleep' => '0',
        '--tries' => '1',
    ]);

    expect($exit)->toBe(0)
        ->and(outboxRedisE2eQueueSize())->toBe(0)
        ->and($subscriber->effectCount())->toBe(1)
        ->and($subscriber->processedEventIds())->toBe([$recorded->id]);

    $delivery = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxDelivery::query()
            ->where('outbox_event_id', $recorded->id)
            ->where('subscriber', IdempotentTestSubscriber::NAME)
            ->firstOrFail(),
    );

    expect($delivery->status)->toBe(OutboxDeliveryStatus::Processed)
        ->and($delivery->processed_at)->not->toBeNull();
});
