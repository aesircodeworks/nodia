<?php

namespace App\Inventory\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;

/**
 * Redis primitives for the on-sale waiting room's entrant lifecycle
 * (stage-10 plan, Data model "Redis structures"). Redis is never
 * authoritative here (system-design 9.2): losing it loses queue
 * positions, which buyers recover by rejoining, so nothing in this class
 * is a system of record. Every key embeds tenant_id, because Redis has
 * no RLS; key namespacing plus the Host-resolved tenant context is the
 * isolation mechanism on this path (stage-10 plan, Data model "Redis
 * structures"). The two per-event keys (waiting, and later the
 * gatekeeper's admitted and budget keys, stage-10 plan task breakdown
 * item 8) additionally wrap tenant_id and event_id together in a literal
 * Redis Cluster hash tag ({...}) so every key for one event lands on the
 * same cluster slot, which is what keeps the gatekeeper's Lua scripts
 * atomic under clustering (stage-10 plan, Risks "Redis loss and
 * fairness").
 *
 * entrantKey() is a reverse lookup this class adds beyond the plan's own
 * enumerated Redis structures (waiting, admitted, active, budget,
 * cache): GET /v1/storefront/queue-entries/{entry} carries only a bare
 * entrant id, resolved against a Host-scoped tenant with no event id in
 * the path, so something has to route from that id to the event's own
 * per-event keys. Its key still embeds tenant_id on its own (not inside
 * the event hash tag, since it is read independently of the gatekeeper's
 * Lua scripts), so a lookup under the wrong tenant simply finds no key
 * (stage-10 plan, Endpoints "GET /v1/storefront/queue-entries/{entry}":
 * "cross-tenant resolves to not-found by key namespacing").
 *
 * admit() (stage-10 plan task breakdown item 8) is the gatekeeper's own
 * Lua-scripted admission step: refilling the budget, popping the earliest
 * arrivals from waiting, writing them to admitted, and charging them
 * against the budget all happen inside one redis.call sequence within a
 * single Lua script, which Redis executes as one atomic, single-threaded
 * unit (stage-10 plan, Data model "Admission and dequeue run as Lua
 * scripts so that popping an entrant from waiting, writing it to
 * admitted, and decrementing the budget are one atomic step; two racing
 * gatekeeper runs cannot admit the same entrant twice or exceed the
 * interval budget").
 *
 * The budget is a token bucket, not a per-minute counter that resets on
 * the minute boundary. Tokens accrue continuously with elapsed time at
 * admission_rate_per_minute (measured on the framework clock the caller
 * passes in, never a Redis TTL, so a fake clock governs it in tests), and
 * the bucket holds at most one tick's worth of them. That cap is the
 * whole point: the waiting room exists to admit entrants into checkout
 * "matched to what the payment path sustains" (stage-10 plan, Overview),
 * and a bucket that could hold a whole minute's rate would let the first
 * tick of each minute dump all of it into checkout at once and then idle,
 * which is the thundering herd the waiting room is there to prevent. The
 * bucket starts empty and accrual is the only thing that ever adds to it,
 * so the admissions in any 60-second window still cannot exceed
 * admission_rate_per_minute; capping only ever discards accrual, and an
 * idle event therefore banks no burst to spend later.
 */
final class OnSaleQueue
{
    private const ACTIVE_SET = 'onsale:active';

    /**
     * The bucket key's own Redis TTL, garbage collection only (stage-10
     * plan, Data model "Redis structures"), never consulted for
     * admission semantics (only the Lua script's own token arithmetic
     * is). Every tick rewrites the key, so this only ever elapses for an
     * event the gatekeeper has stopped ticking, which means no live
     * queue; the bucket then reseeds empty, which under-admits by at
     * most one tick and can never over-admit.
     */
    private const BUCKET_KEY_TTL_SECONDS = 300;

    /**
     * How long past an admitted entry's own expiry score trimAdmitted()
     * waits before removing it (stage-10 plan, Data model "trimmed by
     * the gatekeeper once scores pass, slightly past the admission
     * token lifetime (garbage collection only; token validity is
     * checked against the signed expiry, not Redis)"). Purely
     * housekeeping: GetQueueEntry independently rejects any score at or
     * before the caller's own clock regardless of whether this grace
     * window has elapsed yet.
     */
    private const ADMITTED_TRIM_GRACE_SECONDS = 30;

    /**
     * Refills the event's token bucket for the time elapsed since the
     * last tick, then pops that many of the earliest arrivals from
     * waiting and admits them, as one atomic Lua script (see the class
     * docblock). $rate is the accrual rate, read fresh on every call, so
     * raising or lowering an event's admission_rate_per_minute takes
     * effect from the next tick without retroactively crediting or
     * clawing back tokens already accrued. Returns the admitted entrant
     * ids in arrival order, possibly empty.
     *
     * @return list<string>
     */
    public static function admit(string $tenantId, string $eventId, int $rate, CarbonInterface $now, int $tokenTtlSeconds): array
    {
        $keys = [
            self::waitingKey($tenantId, $eventId),
            self::admittedKey($tenantId, $eventId),
            self::bucketKey($tenantId, $eventId),
        ];

        $args = [
            $now->getPreciseTimestamp(3),
            $tokenTtlSeconds * 1000,
            $rate,
            self::bucketCapacity($rate),
            self::BUCKET_KEY_TTL_SECONDS,
        ];

        // Illuminate\Redis\Connections\PhpRedisConnection::eval()
        // reorders arguments to a Laravel-specific signature
        // ($script, $numberOfKeys, ...$arguments) that differs from
        // native phpredis's own eval(script, args, num_keys) the
        // parent Connection class's @mixin \Redis docblock (and so
        // Larastan) describes; client() reaches the underlying \Redis
        // client directly so this call runs, and is analysed, against
        // one unambiguous signature (mirrors this class's own earlier
        // set()-to-setex() correction for the same @mixin-versus-
        // override mismatch).
        $admitted = Redis::connection()->client()->eval(self::ADMIT_SCRIPT, [...$keys, ...$args], count($keys));

        return is_array($admitted) ? array_values(array_map(strval(...), $admitted)) : [];
    }

    /**
     * Garbage collection only (see the class docblock and
     * ADMITTED_TRIM_GRACE_SECONDS); returns the number of entries
     * removed. Safe to call from any number of concurrent gatekeeper
     * ticks: ZREMRANGEBYSCORE is a single atomic Redis command, and
     * removing an already-past-grace entry has no side effect any
     * racing caller could observe.
     */
    public static function trimAdmitted(string $tenantId, string $eventId, CarbonInterface $now): int
    {
        $threshold = $now->copy()->subSeconds(self::ADMITTED_TRIM_GRACE_SECONDS)->getPreciseTimestamp(3);

        $removed = Redis::connection()->zremrangebyscore(self::admittedKey($tenantId, $eventId), '-inf', (string) $threshold);

        return is_int($removed) ? $removed : 0;
    }

    /**
     * The admitted entrant's own expiry instant (the sorted set's
     * score, stage-10 plan Data model "scored by admission expiry in
     * milliseconds"), or null when the entrant is not, or is no longer,
     * admitted. GetQueueEntry compares this against its own injected
     * clock rather than relying on trimAdmitted() having already run,
     * since Redis TTLs and this GC trim are never semantic (stage-10
     * plan Risks "Fake clock versus Redis TTL").
     */
    public static function admissionExpiry(string $tenantId, string $eventId, string $entrantId): ?CarbonInterface
    {
        $score = Redis::connection()->zscore(self::admittedKey($tenantId, $eventId), $entrantId);

        return is_float($score) ? Date::createFromTimestampMs((int) $score, 'UTC') : null;
    }

    /**
     * Every tenant_id:event_id pair with a live queue (stage-10 plan,
     * Data model "onsale:active... iterated by the gatekeeper"), parsed
     * into its two components. Malformed members (never written by this
     * class) are dropped rather than raised on, since a stray key
     * should not stop the gatekeeper from admitting every well-formed
     * event.
     *
     * @return list<array{tenant_id: string, event_id: string}>
     */
    public static function activeMembers(): array
    {
        $members = Redis::connection()->smembers(self::ACTIVE_SET);

        if (! is_array($members)) {
            return [];
        }

        $parsed = [];

        foreach ($members as $member) {
            $parts = explode(':', $member, 2);

            if (count($parts) === 2) {
                $parsed[] = ['tenant_id' => $parts[0], 'event_id' => $parts[1]];
            }
        }

        return $parsed;
    }

    /**
     * The most tokens the bucket may hold, and so the most entrants one
     * tick may release: exactly the allowance a tick's worth of elapsed
     * time accrues at $rate (see the class docblock). Never below 1, or
     * an event whose rate is slower than one entrant per tick would cap
     * its bucket under a whole token and admit nobody, ever.
     */
    public static function bucketCapacity(int $rate): int
    {
        $tickSeconds = (int) config('onsale.gatekeeper.tick_seconds');

        return max(1, (int) ceil($rate * $tickSeconds / 60));
    }

    /**
     * Records the entrant's arrival and returns its 1-based position
     * (stage-10 plan, Data model "Redis structures": "score is arrival
     * time in milliseconds from the application clock, position is rank
     * plus one"). $arrivedAt is the caller's injected application clock,
     * never read from this class, so arrival ordering is fake-clock
     * testable (master plan Stage 1: "Time control for everything
     * TTL-based").
     */
    public static function join(string $tenantId, string $eventId, string $entrantId, CarbonInterface $arrivedAt): int
    {
        $redis = Redis::connection();

        $redis->zadd(self::waitingKey($tenantId, $eventId), $arrivedAt->getPreciseTimestamp(3), $entrantId);
        $redis->setex(self::entrantKey($tenantId, $entrantId), self::entrantTtlSeconds(), $eventId);
        $redis->sadd(self::ACTIVE_SET, self::activeMember($tenantId, $eventId));

        /** @var int $position */
        $position = self::position($tenantId, $eventId, $entrantId);

        return $position;
    }

    /**
     * Rank plus one (stage-10 plan, Data model "Redis structures");
     * null when the entrant is not, or is no longer, in the waiting set.
     */
    public static function position(string $tenantId, string $eventId, string $entrantId): ?int
    {
        $rank = Redis::connection()->zrank(self::waitingKey($tenantId, $eventId), $entrantId);

        return is_int($rank) ? $rank + 1 : null;
    }

    /**
     * Routes a bare entrant id to the event it joined, scoped to the
     * given tenant; null when no such entrant exists for this tenant
     * (unknown id, cross-tenant id, or its entrantKey() TTL already
     * elapsed).
     */
    public static function eventForEntrant(string $tenantId, string $entrantId): ?string
    {
        $eventId = Redis::connection()->get(self::entrantKey($tenantId, $entrantId));

        return is_string($eventId) ? $eventId : null;
    }

    /**
     * Whether the event has ever had an entrant join (stage-10 plan,
     * Data model "Redis structures": "onsale:active... maintained on
     * first join, iterated by the gatekeeper").
     */
    public static function isActive(string $tenantId, string $eventId): bool
    {
        return (bool) Redis::connection()->sismember(self::ACTIVE_SET, self::activeMember($tenantId, $eventId));
    }

    public static function waitingKey(string $tenantId, string $eventId): string
    {
        return sprintf('onsale:%s:waiting', self::hashTag($tenantId, $eventId));
    }

    public static function admittedKey(string $tenantId, string $eventId): string
    {
        return sprintf('onsale:%s:admitted', self::hashTag($tenantId, $eventId));
    }

    /**
     * One continuous bucket per event, with no interval id in the key:
     * the budget is refilled by elapsed time rather than reset on a
     * boundary, so there is no interval to name.
     */
    public static function bucketKey(string $tenantId, string $eventId): string
    {
        return sprintf('onsale:%s:budget', self::hashTag($tenantId, $eventId));
    }

    public static function entrantKey(string $tenantId, string $entrantId): string
    {
        return sprintf('onsale:%s:entrant:%s', $tenantId, $entrantId);
    }

    public static function activeMember(string $tenantId, string $eventId): string
    {
        return sprintf('%s:%s', $tenantId, $eventId);
    }

    private static function hashTag(string $tenantId, string $eventId): string
    {
        return sprintf('{%s:%s}', $tenantId, $eventId);
    }

    private static function entrantTtlSeconds(): int
    {
        return (int) config('onsale.queue.entrant_ttl_seconds');
    }

    /**
     * KEYS[1] waiting, KEYS[2] admitted, KEYS[3] the event's token
     * bucket (a hash of tokens and updated_at). ARGV[1] now in epoch
     * milliseconds, ARGV[2] the admission token TTL in milliseconds (the
     * score written to admitted), ARGV[3] admission_rate_per_minute (the
     * accrual rate), ARGV[4] the bucket's capacity in tokens, ARGV[5] the
     * bucket key's own Redis TTL in seconds (garbage collection only).
     * Returns the list of admitted entrant ids, arrival-ordered, possibly
     * empty.
     *
     * The bucket is rewritten on every call, including the calls that
     * admit nobody: that is what advances updated_at, so accrual is
     * measured from the last tick rather than compounding from a stale
     * one. A first touch seeds it empty, so the tick that discovers a new
     * queue admits nobody and the one a tick later admits a tick's worth.
     */
    private const ADMIT_SCRIPT = <<<'LUA'
        local waiting_key = KEYS[1]
        local admitted_key = KEYS[2]
        local bucket_key = KEYS[3]
        local now_ms = tonumber(ARGV[1])
        local admission_ttl_ms = tonumber(ARGV[2])
        local rate_per_minute = tonumber(ARGV[3])
        local capacity = tonumber(ARGV[4])
        local bucket_ttl_seconds = tonumber(ARGV[5])
        local minute_ms = 60000

        local tokens = 0
        local state = redis.call('HMGET', bucket_key, 'tokens', 'updated_at')

        if state[1] then
            -- A clock that went backwards accrues nothing rather than
            -- clawing tokens back out of the bucket.
            local elapsed_ms = now_ms - tonumber(state[2])
            if elapsed_ms < 0 then
                elapsed_ms = 0
            end

            tokens = tonumber(state[1]) + (elapsed_ms * rate_per_minute) / minute_ms

            if tokens > capacity then
                tokens = capacity
            end
        end

        local to_admit = math.floor(tokens)
        local waiting_count = redis.call('ZCARD', waiting_key)

        if to_admit > waiting_count then
            to_admit = waiting_count
        end

        local entrants = {}

        if to_admit > 0 then
            entrants = redis.call('ZRANGE', waiting_key, 0, to_admit - 1)

            for i = 1, #entrants do
                redis.call('ZREM', waiting_key, entrants[i])
                redis.call('ZADD', admitted_key, now_ms + admission_ttl_ms, entrants[i])
            end

            tokens = tokens - #entrants
        end

        redis.call('HSET', bucket_key, 'tokens', tokens, 'updated_at', now_ms)
        redis.call('EXPIRE', bucket_key, bucket_ttl_seconds)

        return entrants
        LUA;
}
