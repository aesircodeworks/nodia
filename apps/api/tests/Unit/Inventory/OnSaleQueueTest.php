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

test('admittedKey and bucketKey embed tenant_id and event_id in the same hash tag as waitingKey', function (): void {
    expect(OnSaleQueue::admittedKey($this->tenantId, $this->eventId))
        ->toBe("onsale:{{$this->tenantId}:{$this->eventId}}:admitted")
        ->and(OnSaleQueue::bucketKey($this->tenantId, $this->eventId))
        ->toBe("onsale:{{$this->tenantId}:{$this->eventId}}:budget");
});

test('bucketCapacity is one tick\'s worth of the minute\'s rate, never below one entrant', function (): void {
    config(['onsale.gatekeeper.tick_seconds' => 10]);

    expect(OnSaleQueue::bucketCapacity(600))->toBe(100)
        ->and(OnSaleQueue::bucketCapacity(60))->toBe(10)
        // Slower than one entrant per tick: the bucket still has to hold
        // a whole token or the event would admit nobody, ever.
        ->and(OnSaleQueue::bucketCapacity(1))->toBe(1);
});

/*
 * The bucket starts empty, so the tick that first discovers a queue seeds
 * it and admits nobody; a tick's worth of elapsed time then buys a tick's
 * worth of entrants. Every test below therefore ticks once to seed before
 * measuring what accrual releases.
 */
test('the first tick on a new queue seeds an empty bucket and admits nobody', function (): void {
    $base = now();
    $entrantId = Str::uuid7()->toString();
    OnSaleQueue::join($this->tenantId, $this->eventId, $entrantId, $base);

    expect(OnSaleQueue::admit($this->tenantId, $this->eventId, 60, $base, 300))->toBe([])
        ->and(OnSaleQueue::position($this->tenantId, $this->eventId, $entrantId))->toBe(1);

    cleanupOnSaleQueueEntrant($this->tenantId, $entrantId);
});

test('admit pops the earliest arrivals up to the accrued allowance, in arrival order', function (): void {
    // rate 18/min at a 10s tick accrues 3 tokens per tick.
    $base = now();
    $entrantIds = [];

    foreach (range(0, 4) as $i) {
        $entrantId = Str::uuid7()->toString();
        OnSaleQueue::join($this->tenantId, $this->eventId, $entrantId, $base->copy()->addMilliseconds($i));
        $entrantIds[] = $entrantId;
    }

    OnSaleQueue::admit($this->tenantId, $this->eventId, 18, $base, 300);
    $admitted = OnSaleQueue::admit($this->tenantId, $this->eventId, 18, $base->copy()->addSeconds(10), 300);

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

test('a full minute of ticks admits the whole rate, spread evenly, never in one burst', function (): void {
    // The defect this replaces: rate 60/min with 60 waiting entrants used
    // to admit all 60 on the first tick of the minute and then idle. At a
    // 10s tick the allowance is 10 per tick, so the minute's six ticks
    // must each release 10, and no single tick may release more.
    $base = now();
    $entrantIds = [];

    foreach (range(0, 59) as $i) {
        $entrantId = Str::uuid7()->toString();
        OnSaleQueue::join($this->tenantId, $this->eventId, $entrantId, $base->copy()->addMilliseconds($i));
        $entrantIds[] = $entrantId;
    }

    OnSaleQueue::admit($this->tenantId, $this->eventId, 60, $base, 300);

    $perTick = [];

    foreach (range(1, 6) as $tick) {
        $perTick[] = count(OnSaleQueue::admit(
            $this->tenantId,
            $this->eventId,
            60,
            $base->copy()->addSeconds(10 * $tick),
            300,
        ));
    }

    expect($perTick)->toBe([10, 10, 10, 10, 10, 10])
        ->and(array_sum($perTick))->toBe(60)
        ->and(max($perTick))->toBe(10);

    foreach ($entrantIds as $entrantId) {
        cleanupOnSaleQueueEntrant($this->tenantId, $entrantId);
    }
});

test('an idle event banks no burst: a long quiet gap still releases at most one tick\'s worth', function (): void {
    // The bucket caps at a tick's allowance, so five idle minutes cannot
    // be spent as a 300-entrant surge into checkout the moment entrants
    // arrive.
    $base = now();
    $entrantIds = [];

    foreach (range(0, 29) as $i) {
        $entrantId = Str::uuid7()->toString();
        OnSaleQueue::join($this->tenantId, $this->eventId, $entrantId, $base->copy()->addMilliseconds($i));
        $entrantIds[] = $entrantId;
    }

    OnSaleQueue::admit($this->tenantId, $this->eventId, 60, $base, 300);

    $afterLongGap = OnSaleQueue::admit($this->tenantId, $this->eventId, 60, $base->copy()->addMinutes(5), 300);

    expect($afterLongGap)->toHaveCount(10);

    foreach ($entrantIds as $entrantId) {
        cleanupOnSaleQueueEntrant($this->tenantId, $entrantId);
    }
});

test('dense ticks cannot exceed the minute\'s rate: accrual, not tick count, governs', function (): void {
    // Ticking six times as often must not admit six times as many. The
    // per-60s ceiling is what exit criterion 5 guarantees.
    $base = now();
    $entrantIds = [];

    foreach (range(0, 59) as $i) {
        $entrantId = Str::uuid7()->toString();
        OnSaleQueue::join($this->tenantId, $this->eventId, $entrantId, $base->copy()->addMilliseconds($i));
        $entrantIds[] = $entrantId;
    }

    $admitted = 0;

    // A tick every second for a full minute, rate 60/min.
    foreach (range(0, 60) as $second) {
        $admitted += count(OnSaleQueue::admit(
            $this->tenantId,
            $this->eventId,
            60,
            $base->copy()->addSeconds($second),
            300,
        ));
    }

    expect($admitted)->toBe(60);

    foreach ($entrantIds as $entrantId) {
        cleanupOnSaleQueueEntrant($this->tenantId, $entrantId);
    }
});

test('a rate raised mid-flight takes effect from the next tick without retroactive credit', function (): void {
    $base = now();
    $entrantIds = [];

    foreach (range(0, 19) as $i) {
        $entrantId = Str::uuid7()->toString();
        OnSaleQueue::join($this->tenantId, $this->eventId, $entrantId, $base->copy()->addMilliseconds($i));
        $entrantIds[] = $entrantId;
    }

    OnSaleQueue::admit($this->tenantId, $this->eventId, 6, $base, 300);

    // 6/min accrues 1 per 10s tick; 60/min accrues 10. The raise buys the
    // new rate's allowance for the tick that just elapsed, not a refund of
    // the ticks that came before it.
    $atOldRate = OnSaleQueue::admit($this->tenantId, $this->eventId, 6, $base->copy()->addSeconds(10), 300);
    $atNewRate = OnSaleQueue::admit($this->tenantId, $this->eventId, 60, $base->copy()->addSeconds(20), 300);

    expect($atOldRate)->toHaveCount(1)
        ->and($atNewRate)->toHaveCount(10);

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

    // Seed the bucket, then admit a tick later once accrual has bought a
    // token; the expiry score is stamped from the admitting tick's clock.
    OnSaleQueue::admit($this->tenantId, $this->eventId, 60, $base, 300);
    $admittedAt = $base->copy()->addSeconds(10);
    OnSaleQueue::admit($this->tenantId, $this->eventId, 60, $admittedAt, 300);

    $expiry = OnSaleQueue::admissionExpiry($this->tenantId, $this->eventId, $entrantId);

    expect($expiry)->not->toBeNull()
        ->and($expiry->getTimestamp())->toBe($admittedAt->copy()->addSeconds(300)->getTimestamp());

    cleanupOnSaleQueueEntrant($this->tenantId, $entrantId);
});

test('trimAdmitted removes only admitted entries whose expiry has passed the grace window', function (): void {
    $base = now();
    $stale = Str::uuid7()->toString();
    $fresh = Str::uuid7()->toString();

    OnSaleQueue::join($this->tenantId, $this->eventId, $stale, $base);
    OnSaleQueue::admit($this->tenantId, $this->eventId, 60, $base, 1);

    // Admitted a tick later, on a 1s admission token: expires at base+11s.
    OnSaleQueue::admit($this->tenantId, $this->eventId, 60, $base->copy()->addSeconds(10), 1);

    $later = $base->copy()->addMinute();
    OnSaleQueue::join($this->tenantId, $this->eventId, $fresh, $later);
    OnSaleQueue::admit($this->tenantId, $this->eventId, 60, $later, 600);

    // base+2min is past the stale entry's expiry plus the 30s grace, and
    // nowhere near the fresh entry's 600s token.
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
