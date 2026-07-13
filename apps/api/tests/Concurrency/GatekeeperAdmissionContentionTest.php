<?php

use App\Inventory\Support\OnSaleQueue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Concurrency\Support\ParallelRunner;

/*
 * Stage-10 plan, TDD sequencing Slice 5 (Concurrency, first, mandated),
 * task breakdown item 8: "two gatekeeper processes racing on one queue
 * admit at most the interval budget with no entrant admitted twice (Lua
 * atomicity proven by affected-entrant accounting)." Drives
 * App\Inventory\Support\OnSaleQueue::admit() directly in forked worker
 * processes against one shared, real Redis instance, isolated from the
 * full RunGatekeeperTick/GatekeeperCommand plumbing this proof is not
 * about. Redis::purge() before forking and again inside each fork
 * mirrors Tests\Concurrency\Support\ParallelRunner's own per-fork
 * Postgres connection discipline: a forked child inherits the parent's
 * open socket, and two processes writing the RESP wire protocol down
 * one shared socket corrupts it, which would make any admit() failure
 * here a connection-handling artifact rather than a real proof of (or
 * disproof of) the Lua script's atomicity.
 */

const GATEKEEPER_TOKEN_TTL_SECONDS = 300;

beforeEach(function (): void {
    $this->tenantId = Str::uuid7()->toString();
    $this->eventId = Str::uuid7()->toString();
});

afterEach(function (): void {
    $redis = Redis::connection();

    $redis->del(OnSaleQueue::waitingKey($this->tenantId, $this->eventId));
    $redis->del(OnSaleQueue::admittedKey($this->tenantId, $this->eventId));
    $redis->srem('onsale:active', OnSaleQueue::activeMember($this->tenantId, $this->eventId));

    foreach ($redis->keys('onsale:{'.$this->tenantId.':'.$this->eventId.'}:budget:*') as $key) {
        $redis->del($key);
    }
});

function seedGatekeeperWaitingRoom(string $tenantId, string $eventId, int $count): void
{
    $base = now();

    for ($i = 0; $i < $count; $i++) {
        OnSaleQueue::join($tenantId, $eventId, Str::uuid7()->toString(), $base->copy()->addMilliseconds($i));
    }
}

it('admits at most the interval budget with no entrant admitted twice under racing gatekeeper runs', function (int $waiting, int $rate, int $workers): void {
    $tenantId = $this->tenantId;
    $eventId = $this->eventId;

    seedGatekeeperWaitingRoom($tenantId, $eventId, $waiting);

    $now = now();

    Redis::purge();

    $results = ParallelRunner::run($workers, function (PDO $pdo) use ($tenantId, $eventId, $rate, $now): array {
        Redis::purge();

        return OnSaleQueue::admit($tenantId, $eventId, $rate, $now, GATEKEEPER_TOKEN_TTL_SECONDS);
    });

    $admitted = array_merge(...$results);
    $expectedAdmitted = min($waiting, $rate);

    // If this assertion ever fails, the runner is not producing genuine
    // contention and the whole suite proves nothing (mirrors
    // tests/Concurrency/CounterContentionTest.php's own precedent):
    // strengthen the runner rather than weaken the invariant below.
    expect($workers)->toBeGreaterThan(1);

    expect(count($admitted))->toBe($expectedAdmitted)
        ->and(array_unique($admitted))->toHaveCount($expectedAdmitted);

    $redis = Redis::connection();
    $interval = OnSaleQueue::intervalId($now);

    expect((int) $redis->zcard(OnSaleQueue::waitingKey($tenantId, $eventId)))->toBe($waiting - $expectedAdmitted)
        ->and((int) $redis->zcard(OnSaleQueue::admittedKey($tenantId, $eventId)))->toBe($expectedAdmitted)
        ->and((int) $redis->get(OnSaleQueue::budgetKey($tenantId, $eventId, $interval)))->toBe($rate - $expectedAdmitted);

    foreach ($admitted as $entrantId) {
        cleanupGatekeeperEntrant($tenantId, $entrantId);
    }
})->with([
    'oversubscribed: 5 workers race for a queue of 40 against a rate of 10' => [40, 10, 5],
    'exact fit: 5 workers race for a queue of 10 against a rate of 10' => [10, 10, 5],
    'undersubscribed: 5 workers race for a queue of 3 against a rate of 10' => [3, 10, 5],
]);

function cleanupGatekeeperEntrant(string $tenantId, string $entrantId): void
{
    Redis::connection()->del(OnSaleQueue::entrantKey($tenantId, $entrantId));
}
