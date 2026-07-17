<?php

namespace App\Support\Outbox;

use Illuminate\Support\Facades\DB;

/**
 * A per-projection PostgreSQL advisory lock (stage-11 plan, task 13
 * "reporting:rebuild {projection} {--tenant=} {--verify}"): the rebuild
 * command takes it exclusively for the whole run, ProjectionLockedSubscriber
 * handlers take it shared around their own write, so a rebuild can never
 * interleave with a live delivery on the same projection (stage-11 plan,
 * Risks "Rebuild versus live deliveries"; exit criterion 4).
 *
 * Session-scoped (pg_advisory_lock/pg_advisory_unlock), not transaction-
 * scoped: the exclusive side spans several tenant-scoped transactions in
 * one command run (one asTenant() per tenant), which pg_advisory_xact_lock
 * could not hold across, since it releases at each transaction's own
 * commit. The shared side is acquired and released tightly around one
 * projector effect inside its own single tenant transaction, so a plain
 * try/finally is equivalent to the xact-scoped variant there without
 * mixing lock scopes across the two callers.
 *
 * Keyed by hashtext() of a fixed namespace plus the projection name (the
 * registered outbox subscriber name), using Postgres's two-int4-argument
 * advisory lock functions, so unrelated future advisory-lock users (none
 * yet) cannot collide with this one on the same 64-bit key space.
 */
final class ProjectionLock
{
    private const string NAMESPACE = 'reporting_projection_rebuild';

    public function acquireExclusive(string $projection): void
    {
        DB::select('select pg_advisory_lock(hashtext(?), hashtext(?))', [self::NAMESPACE, $projection]);
    }

    public function releaseExclusive(string $projection): void
    {
        DB::select('select pg_advisory_unlock(hashtext(?), hashtext(?))', [self::NAMESPACE, $projection]);
    }

    public function tryAcquireShared(string $projection): bool
    {
        /** @var object{acquired: bool} $row */
        $row = DB::selectOne('select pg_try_advisory_lock_shared(hashtext(?), hashtext(?)) as acquired', [self::NAMESPACE, $projection]);

        return (bool) $row->acquired;
    }

    public function releaseShared(string $projection): void
    {
        DB::select('select pg_advisory_unlock_shared(hashtext(?), hashtext(?))', [self::NAMESPACE, $projection]);
    }
}
