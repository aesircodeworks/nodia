<?php

namespace App\Inventory\Support;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;

/**
 * The read-through Redis cache fronting the storefront availability and
 * seats endpoints (stage-10 plan, Endpoints "GET /v1/storefront/events/
 * {event}/availability" and ".../seats", Data model "Redis structures"
 * cache-keys paragraph; task breakdown item 10). Redis is never
 * authoritative here (system-design 9.2, stage-10 plan Scope): the
 * caller's own compute closure always reads PostgreSQL through the same
 * Actions Stage 6 already shipped (App\Inventory\Actions\
 * GetEventAvailability, GetStorefrontEventSeats), so a cache miss, a
 * Redis outage, or an expired entry only ever falls back to that same
 * authoritative read, never to a different code path. Nothing in the
 * hold-creation path (App\Inventory\Actions\CreateHold) writes to or
 * reads from this class, matching the plan's own instruction that the
 * cache "is never written by the hold path" and "correctness always
 * comes from PostgreSQL".
 *
 * Freshness is judged against the caller's own injected CarbonInterface
 * ("now > cached_at + ttl" means stale, stage-10 plan Data model),
 * mirroring App\Inventory\Support\OnSaleQueue's own posture of taking the
 * clock as a parameter rather than reading it internally, so freshness
 * is fake-clock testable with no real waiting. The Redis TTL this class
 * sets on write is deliberately a little above the caller's own
 * second-level TTL, purely for eventual cleanup of an entry nothing will
 * ever read again as fresh; it carries no freshness semantics of its own
 * (stage-10 plan, Risks "Fake clock versus Redis TTL").
 *
 * Every key embeds tenant_id and event_id (stage-10 plan, Data model
 * "Redis structures": "All keys embed tenant_id and event_id, because
 * Redis has no RLS"), wrapped in the same Redis Cluster hash tag
 * App\Inventory\Support\OnSaleQueue's own per-event keys use, for the
 * same cluster-safety reasons (not strictly required here, since nothing
 * touches two cache keys in one atomic script, but consistent with every
 * other per-event key this stage writes).
 */
final class ReadCache
{
    /**
     * How far above the caller's own second-level TTL the Redis key's own
     * expiry is set, garbage collection only (see class docblock).
     */
    private const CLEANUP_GRACE_SECONDS = 10;

    public static function key(string $tenantId, string $eventId, string $kind): string
    {
        return sprintf('onsale:{%s:%s}:cache:%s', $tenantId, $eventId, $kind);
    }

    /**
     * "now > cached_at + ttl" means stale (stage-10 plan, Data model
     * "Redis structures"); an entry exactly at the boundary is still
     * fresh, mirroring the exact-instant convention App\Inventory\
     * Support\AdmissionToken already applies to its own expiry check.
     */
    public static function isStale(CarbonInterface $cachedAt, int $ttlSeconds, CarbonInterface $now): bool
    {
        return $now->gt($cachedAt->copy()->addSeconds($ttlSeconds));
    }

    /**
     * Read-through: serves the cached payload when a fresh entry exists,
     * otherwise calls $compute(), caches its result under $now, and
     * returns it. $compute() is never called on a fresh hit, and an
     * exception from $compute() propagates without writing anything to
     * the cache (a nonexistent or unpublished event's own
     * HoldEventNotFoundException, for instance, is never cached).
     *
     * @param  Closure(): array<string, mixed>  $compute
     * @return array<string, mixed>
     */
    public static function remember(string $tenantId, string $eventId, string $kind, int $ttlSeconds, CarbonInterface $now, Closure $compute): array
    {
        $key = self::key($tenantId, $eventId, $kind);
        $cached = self::get($key);

        if ($cached !== null && ! self::isStale($cached['cached_at'], $ttlSeconds, $now)) {
            return $cached['payload'];
        }

        $payload = $compute();

        self::put($key, $payload, $now, $ttlSeconds);

        return $payload;
    }

    /**
     * @return array{payload: array<string, mixed>, cached_at: CarbonInterface}|null
     */
    public static function get(string $key): ?array
    {
        $raw = Redis::connection()->get($key);

        if (! is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || ! is_array($decoded['payload'] ?? null) || ! is_string($decoded['cached_at'] ?? null)) {
            return null;
        }

        return ['payload' => $decoded['payload'], 'cached_at' => Date::parse($decoded['cached_at'])];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function put(string $key, array $payload, CarbonInterface $cachedAt, int $ttlSeconds): void
    {
        $body = json_encode(['payload' => $payload, 'cached_at' => $cachedAt->toIso8601String()]);

        Redis::connection()->setex($key, $ttlSeconds + self::CLEANUP_GRACE_SECONDS, $body);
    }
}
