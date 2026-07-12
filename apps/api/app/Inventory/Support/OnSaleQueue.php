<?php

namespace App\Inventory\Support;

use Carbon\CarbonInterface;
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
 */
final class OnSaleQueue
{
    private const ACTIVE_SET = 'onsale:active';

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
}
