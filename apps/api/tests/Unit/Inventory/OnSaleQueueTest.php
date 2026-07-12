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
    Redis::connection()->del(OnSaleQueue::waitingKey($this->tenantId, $this->eventId));
    Redis::connection()->srem('onsale:active', OnSaleQueue::activeMember($this->tenantId, $this->eventId));
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
