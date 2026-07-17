<?php

use App\Inventory\Support\ReadCache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/*
 * Stage-10 plan, TDD sequencing Slice 7 (Unit, first), task breakdown
 * item 10: clock-aware freshness, cache key derivation, and the read-
 * through remember() primitive, isolated from the two Actions
 * (App\Inventory\Actions\GetCachedEventAvailability, GetCachedStorefrontEventSeats)
 * that call this in the same task. Runs against real Redis, mirroring
 * tests/Unit/Inventory/OnSaleQueueTest.php's own precedent; every key
 * this suite writes is deleted in afterEach so no test leaks state into
 * another.
 */

beforeEach(function (): void {
    $this->tenantId = Str::uuid7()->toString();
    $this->eventId = Str::uuid7()->toString();
});

afterEach(function (): void {
    $redis = Redis::connection();

    $redis->del(ReadCache::key($this->tenantId, $this->eventId, 'availability'));
    $redis->del(ReadCache::key($this->tenantId, $this->eventId, 'seats'));
});

test('isStale is false up to and including the exact TTL boundary', function (): void {
    $cachedAt = now();

    expect(ReadCache::isStale($cachedAt, 2, $cachedAt))->toBeFalse()
        ->and(ReadCache::isStale($cachedAt, 2, $cachedAt->copy()->addSecond()))->toBeFalse()
        ->and(ReadCache::isStale($cachedAt, 2, $cachedAt->copy()->addSeconds(2)))->toBeFalse();
});

test('isStale is true the instant now passes cached_at plus ttl', function (): void {
    $cachedAt = now();

    expect(ReadCache::isStale($cachedAt, 2, $cachedAt->copy()->addSeconds(2)->addMillisecond()))->toBeTrue()
        ->and(ReadCache::isStale($cachedAt, 2, $cachedAt->copy()->addSeconds(10)))->toBeTrue();
});

test('key embeds tenant_id and event_id and is distinct per kind', function (): void {
    $availabilityKey = ReadCache::key($this->tenantId, $this->eventId, 'availability');
    $seatsKey = ReadCache::key($this->tenantId, $this->eventId, 'seats');

    expect($availabilityKey)->toContain($this->tenantId)
        ->and($availabilityKey)->toContain($this->eventId)
        ->and($availabilityKey)->not->toBe($seatsKey);

    $otherEventId = Str::uuid7()->toString();
    $otherTenantId = Str::uuid7()->toString();

    expect(ReadCache::key($this->tenantId, $otherEventId, 'availability'))->not->toBe($availabilityKey)
        ->and(ReadCache::key($otherTenantId, $this->eventId, 'availability'))->not->toBe($availabilityKey);
});

test('remember computes and caches on a miss, then serves the cached payload without recomputing while fresh', function (): void {
    $now = now();
    $calls = 0;
    $compute = function () use (&$calls): array {
        $calls++;

        return ['n' => $calls];
    };

    $first = ReadCache::remember($this->tenantId, $this->eventId, 'availability', 2, $now, $compute);
    $second = ReadCache::remember($this->tenantId, $this->eventId, 'availability', 2, $now->copy()->addSecond(), $compute);

    expect($first)->toBe(['n' => 1])
        ->and($second)->toBe(['n' => 1])
        ->and($calls)->toBe(1);
});

test('remember recomputes once the cached entry is stale against the injected clock', function (): void {
    $now = now();
    $calls = 0;
    $compute = function () use (&$calls): array {
        $calls++;

        return ['n' => $calls];
    };

    ReadCache::remember($this->tenantId, $this->eventId, 'availability', 2, $now, $compute);
    $stale = ReadCache::remember($this->tenantId, $this->eventId, 'availability', 2, $now->copy()->addSeconds(3), $compute);

    expect($stale)->toBe(['n' => 2])
        ->and($calls)->toBe(2);
});

test('put stores a payload readable by a subsequent remember call within ttl', function (): void {
    $now = now();

    ReadCache::put(ReadCache::key($this->tenantId, $this->eventId, 'availability'), ['poisoned' => true], $now, 60);

    $served = ReadCache::remember($this->tenantId, $this->eventId, 'availability', 60, $now->copy()->addSecond(), fn (): array => ['poisoned' => false]);

    expect($served)->toBe(['poisoned' => true]);
});

test('availability and seats cache TTL defaults come from config/onsale.php', function (): void {
    expect(config('onsale.cache.availability_ttl_seconds'))->toBe(2)
        ->and(config('onsale.cache.seats_ttl_seconds'))->toBe(2);
});
