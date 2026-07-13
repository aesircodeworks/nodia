<?php

use App\Inventory\Support\OnSaleQueue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/*
 * Stage-10 plan, TDD sequencing Slice 4 (Unit, first), task breakdown
 * item 7: position arithmetic from the waiting sorted set's rank and
 * arrival ordering from the injected clock, isolated from the join and
 * poll endpoints that call this in the same task. Runs against real
 * Redis (CI always provides it, mirroring tests/Feature/Support/Outbox/
 * OutboxRedisDeliveryTest.php's own precedent); every key this suite
 * writes is deleted in afterEach so no test leaks state into another.
 */

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

function cleanupOnSaleQueueEntrant(string $tenantId, string $entrantId): void
{
    Redis::connection()->del(OnSaleQueue::entrantKey($tenantId, $entrantId));
}

test('the first joiner is position 1', function (): void {
    $entrantId = Str::uuid7()->toString();

    $position = OnSaleQueue::join($this->tenantId, $this->eventId, $entrantId, now());

    expect($position)->toBe(1);

    cleanupOnSaleQueueEntrant($this->tenantId, $entrantId);
});

test('position is rank plus one for each successive joiner', function (): void {
    $first = Str::uuid7()->toString();
    $second = Str::uuid7()->toString();
    $third = Str::uuid7()->toString();
    $base = now();

    OnSaleQueue::join($this->tenantId, $this->eventId, $first, $base);
    OnSaleQueue::join($this->tenantId, $this->eventId, $second, $base->copy()->addMillisecond());
    OnSaleQueue::join($this->tenantId, $this->eventId, $third, $base->copy()->addMilliseconds(2));

    expect(OnSaleQueue::position($this->tenantId, $this->eventId, $first))->toBe(1)
        ->and(OnSaleQueue::position($this->tenantId, $this->eventId, $second))->toBe(2)
        ->and(OnSaleQueue::position($this->tenantId, $this->eventId, $third))->toBe(3);

    cleanupOnSaleQueueEntrant($this->tenantId, $first);
    cleanupOnSaleQueueEntrant($this->tenantId, $second);
    cleanupOnSaleQueueEntrant($this->tenantId, $third);
});

test('arrival order is decided by the injected clock, not join call order', function (): void {
    $calledFirst = Str::uuid7()->toString();
    $calledSecond = Str::uuid7()->toString();
    $base = now();

    // calledFirst is joined first but arrives later on the clock, so it
    // must still rank behind calledSecond.
    OnSaleQueue::join($this->tenantId, $this->eventId, $calledFirst, $base->copy()->addSeconds(5));
    OnSaleQueue::join($this->tenantId, $this->eventId, $calledSecond, $base);

    expect(OnSaleQueue::position($this->tenantId, $this->eventId, $calledSecond))->toBe(1)
        ->and(OnSaleQueue::position($this->tenantId, $this->eventId, $calledFirst))->toBe(2);

    cleanupOnSaleQueueEntrant($this->tenantId, $calledFirst);
    cleanupOnSaleQueueEntrant($this->tenantId, $calledSecond);
});

test('position is null for an entrant that never joined', function (): void {
    expect(OnSaleQueue::position($this->tenantId, $this->eventId, Str::uuid7()->toString()))->toBeNull();
});

test('eventForEntrant resolves the joined event, scoped to the joining tenant only', function (): void {
    $entrantId = Str::uuid7()->toString();

    OnSaleQueue::join($this->tenantId, $this->eventId, $entrantId, now());

    expect(OnSaleQueue::eventForEntrant($this->tenantId, $entrantId))->toBe($this->eventId)
        ->and(OnSaleQueue::eventForEntrant(Str::uuid7()->toString(), $entrantId))->toBeNull();

    cleanupOnSaleQueueEntrant($this->tenantId, $entrantId);
});

test('eventForEntrant is null for an entrant that never joined', function (): void {
    expect(OnSaleQueue::eventForEntrant($this->tenantId, Str::uuid7()->toString()))->toBeNull();
});

test('the first join marks the event active in the global active set', function (): void {
    $entrantId = Str::uuid7()->toString();

    expect(OnSaleQueue::isActive($this->tenantId, $this->eventId))->toBeFalse();

    OnSaleQueue::join($this->tenantId, $this->eventId, $entrantId, now());

    expect(OnSaleQueue::isActive($this->tenantId, $this->eventId))->toBeTrue();

    cleanupOnSaleQueueEntrant($this->tenantId, $entrantId);
});

test('waiting and entrant keys embed tenant_id and event_id, and active membership embeds both', function (): void {
    expect(OnSaleQueue::waitingKey($this->tenantId, $this->eventId))
        ->toBe("onsale:{{$this->tenantId}:{$this->eventId}}:waiting")
        ->and(OnSaleQueue::entrantKey($this->tenantId, 'entrant-1'))
        ->toBe("onsale:{$this->tenantId}:entrant:entrant-1")
        ->and(OnSaleQueue::activeMember($this->tenantId, $this->eventId))
        ->toBe("{$this->tenantId}:{$this->eventId}");
});

/*
 * Stage-10 plan, TDD sequencing Slice 5 (Unit, first), task breakdown
 * item 8: the gatekeeper's own admit()/trimAdmitted() Lua-scripted
 * primitives, isolated from the RunGatekeeperTick action and the
 * concurrency proof (tests/Concurrency/GatekeeperAdmissionContentionTest
 * .php) that exercise this same script under real racing processes.
 */

test('admittedKey and budgetKey embed tenant_id and event_id in the same hash tag as waitingKey', function (): void {
    expect(OnSaleQueue::admittedKey($this->tenantId, $this->eventId))
        ->toBe("onsale:{{$this->tenantId}:{$this->eventId}}:admitted")
        ->and(OnSaleQueue::budgetKey($this->tenantId, $this->eventId, 12345))
        ->toBe("onsale:{{$this->tenantId}:{$this->eventId}}:budget:12345");
});

test('intervalId buckets by the current UTC minute', function (): void {
    $base = now()->startOfMinute();

    expect(OnSaleQueue::intervalId($base))->toBe(OnSaleQueue::intervalId($base->copy()->addSeconds(59)))
        ->and(OnSaleQueue::intervalId($base))->not->toBe(OnSaleQueue::intervalId($base->copy()->addMinute()));
});

test('admit pops the earliest arrivals up to the rate, in arrival order', function (): void {
    $base = now();
    $entrantIds = [];

    foreach (range(0, 4) as $i) {
        $entrantId = Str::uuid7()->toString();
        OnSaleQueue::join($this->tenantId, $this->eventId, $entrantId, $base->copy()->addMilliseconds($i));
        $entrantIds[] = $entrantId;
    }

    $admitted = OnSaleQueue::admit($this->tenantId, $this->eventId, 3, $base, 300);

    expect($admitted)->toBe(array_slice($entrantIds, 0, 3))
        ->and(OnSaleQueue::position($this->tenantId, $this->eventId, $entrantIds[3]))->toBe(1)
        ->and(OnSaleQueue::position($this->tenantId, $this->eventId, $entrantIds[4]))->toBe(2)
        ->and(OnSaleQueue::position($this->tenantId, $this->eventId, $entrantIds[0]))->toBeNull();

    foreach ($entrantIds as $entrantId) {
        cleanupOnSaleQueueEntrant($this->tenantId, $entrantId);
    }
});

test('admit returns an empty list when the waiting set is empty', function (): void {
    expect(OnSaleQueue::admit($this->tenantId, $this->eventId, 5, now(), 300))->toBe([]);
});

test('per-interval budget arithmetic: repeated admit calls in the same interval share one capped budget', function (): void {
    // Anchored to a fixed offset into the minute (not bare now()) so
    // the +5s/+10s ticks below can never cross an interval boundary by
    // accident of wall-clock timing.
    $base = now()->startOfMinute()->addSeconds(5);
    $entrantIds = [];

    foreach (range(0, 4) as $i) {
        $entrantId = Str::uuid7()->toString();
        OnSaleQueue::join($this->tenantId, $this->eventId, $entrantId, $base->copy()->addMilliseconds($i));
        $entrantIds[] = $entrantId;
    }

    $firstTick = OnSaleQueue::admit($this->tenantId, $this->eventId, 2, $base, 300);
    $secondTick = OnSaleQueue::admit($this->tenantId, $this->eventId, 2, $base->copy()->addSeconds(5), 300);
    $thirdTick = OnSaleQueue::admit($this->tenantId, $this->eventId, 2, $base->copy()->addSeconds(10), 300);

    expect($firstTick)->toHaveCount(2)
        ->and($secondTick)->toBe([])
        ->and($thirdTick)->toBe([])
        ->and(OnSaleQueue::position($this->tenantId, $this->eventId, $entrantIds[2]))->toBe(1);

    foreach ($entrantIds as $entrantId) {
        cleanupOnSaleQueueEntrant($this->tenantId, $entrantId);
    }
});

test('a new interval grants a fresh budget', function (): void {
    $base = now();
    $entrantIds = [];

    foreach (range(0, 3) as $i) {
        $entrantId = Str::uuid7()->toString();
        OnSaleQueue::join($this->tenantId, $this->eventId, $entrantId, $base->copy()->addMilliseconds($i));
        $entrantIds[] = $entrantId;
    }

    $firstTick = OnSaleQueue::admit($this->tenantId, $this->eventId, 2, $base, 300);
    $nextIntervalTick = OnSaleQueue::admit($this->tenantId, $this->eventId, 2, $base->copy()->addMinute(), 300);

    expect($firstTick)->toHaveCount(2)
        ->and($nextIntervalTick)->toHaveCount(2);

    foreach ($entrantIds as $entrantId) {
        cleanupOnSaleQueueEntrant($this->tenantId, $entrantId);
    }
});

test('a later admit() call in the same interval never retroactively changes an already-seeded budget', function (): void {
    // Same fixed-offset anchoring as the budget-arithmetic test above.
    $base = now()->startOfMinute()->addSeconds(5);
    $entrantIds = [];

    foreach (range(0, 2) as $i) {
        $entrantId = Str::uuid7()->toString();
        OnSaleQueue::join($this->tenantId, $this->eventId, $entrantId, $base->copy()->addMilliseconds($i));
        $entrantIds[] = $entrantId;
    }

    $firstTick = OnSaleQueue::admit($this->tenantId, $this->eventId, 1, $base, 300);
    $secondTick = OnSaleQueue::admit($this->tenantId, $this->eventId, 10, $base->copy()->addSeconds(5), 300);

    expect($firstTick)->toHaveCount(1)
        ->and($secondTick)->toBe([]);

    foreach ($entrantIds as $entrantId) {
        cleanupOnSaleQueueEntrant($this->tenantId, $entrantId);
    }
});

test('admissionExpiry reports the token-lifetime score for an admitted entrant and null for a waiting or unknown one', function (): void {
    $base = now();
    $entrantId = Str::uuid7()->toString();
    OnSaleQueue::join($this->tenantId, $this->eventId, $entrantId, $base);

    expect(OnSaleQueue::admissionExpiry($this->tenantId, $this->eventId, $entrantId))->toBeNull()
        ->and(OnSaleQueue::admissionExpiry($this->tenantId, $this->eventId, Str::uuid7()->toString()))->toBeNull();

    OnSaleQueue::admit($this->tenantId, $this->eventId, 1, $base, 300);

    $expiry = OnSaleQueue::admissionExpiry($this->tenantId, $this->eventId, $entrantId);

    expect($expiry)->not->toBeNull()
        ->and($expiry->getTimestamp())->toBe($base->copy()->addSeconds(300)->getTimestamp());

    cleanupOnSaleQueueEntrant($this->tenantId, $entrantId);
});

test('trimAdmitted removes only admitted entries whose expiry has passed the grace window', function (): void {
    $base = now();
    $stale = Str::uuid7()->toString();
    $fresh = Str::uuid7()->toString();

    OnSaleQueue::join($this->tenantId, $this->eventId, $stale, $base);
    OnSaleQueue::admit($this->tenantId, $this->eventId, 1, $base, 1);

    $laterInterval = $base->copy()->addMinute();
    OnSaleQueue::join($this->tenantId, $this->eventId, $fresh, $laterInterval);
    OnSaleQueue::admit($this->tenantId, $this->eventId, 1, $laterInterval, 600);

    OnSaleQueue::trimAdmitted($this->tenantId, $this->eventId, $base->copy()->addMinutes(2));

    expect(OnSaleQueue::admissionExpiry($this->tenantId, $this->eventId, $stale))->toBeNull()
        ->and(OnSaleQueue::admissionExpiry($this->tenantId, $this->eventId, $fresh))->not->toBeNull();

    cleanupOnSaleQueueEntrant($this->tenantId, $stale);
    cleanupOnSaleQueueEntrant($this->tenantId, $fresh);
});

test('activeMembers parses every tenant_id:event_id pair from the global active set', function (): void {
    $otherEventId = Str::uuid7()->toString();
    $entrantId = Str::uuid7()->toString();
    $otherEntrantId = Str::uuid7()->toString();

    OnSaleQueue::join($this->tenantId, $this->eventId, $entrantId, now());
    OnSaleQueue::join($this->tenantId, $otherEventId, $otherEntrantId, now());

    $members = OnSaleQueue::activeMembers();

    expect($members)->toContain(['tenant_id' => $this->tenantId, 'event_id' => $this->eventId])
        ->and($members)->toContain(['tenant_id' => $this->tenantId, 'event_id' => $otherEventId]);

    cleanupOnSaleQueueEntrant($this->tenantId, $entrantId);
    cleanupOnSaleQueueEntrant($this->tenantId, $otherEntrantId);
    Redis::connection()->del(OnSaleQueue::waitingKey($this->tenantId, $otherEventId));
    Redis::connection()->srem('onsale:active', OnSaleQueue::activeMember($this->tenantId, $otherEventId));
});
