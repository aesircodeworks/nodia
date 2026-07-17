<?php

namespace App\Console\Commands;

use App\Reporting\Support\Rebuild\ReportingProjectionRebuilder;
use App\Support\Outbox\ProjectionLock;
use Illuminate\Console\Command;
use LogicException;

/**
 * Rebuilds or verifies one reporting projection from outbox replay
 * (stage-11 plan, task 13; system-design 9.1's replay primitive). Not an
 * endpoint: an operational command, run at deploy time to backfill a
 * projection subscribed to already-flowing events (stage-11 plan, Risks
 * "Adding reporting subscriptions to already-flowing events"), or ad hoc
 * to repair drift.
 *
 * Without --verify: deletes {projection}'s rows for --tenant (or every
 * tenant the eligible event stream touches) and replays them, one tenant
 * transaction at a time.
 *
 * With --verify: replays into an in-memory aggregate per tenant and
 * reports drift against the live table without writing anything; exits
 * non-zero when drift is found so the command is script-friendly.
 *
 * Takes App\Support\Outbox\ProjectionLock exclusively for the whole run
 * so a live projector delivery (ProcessOutboxDelivery, which takes the
 * same lock shared around a ProjectionLockedSubscriber's effect) can
 * never interleave with a rebuild or a verify pass on the same
 * projection (stage-11 plan, Risks "Rebuild versus live deliveries";
 * exit criterion 4).
 */
class ReportingRebuildCommand extends Command
{
    protected $signature = 'reporting:rebuild
                            {projection : Registered reporting projection (outbox subscriber) name}
                            {--tenant= : Rebuild or verify only this tenant; omit to cover every tenant the eligible event stream touches}
                            {--verify : Report drift against the live table without writing}';

    protected $description = 'Rebuild or verify a reporting projection from outbox replay';

    public function handle(ReportingProjectionRebuilder $rebuilder, ProjectionLock $lock): int
    {
        $projection = (string) $this->argument('projection');
        $tenantOption = $this->option('tenant');
        $tenantId = ($tenantOption !== null && $tenantOption !== '') ? (string) $tenantOption : null;

        $lock->acquireExclusive($projection);

        try {
            if ($this->option('verify')) {
                return $this->verify($rebuilder, $projection, $tenantId);
            }

            $count = $rebuilder->rebuild($projection, $tenantId);

            $this->info("Rebuilt [{$projection}] from {$count} outbox event(s).");

            return self::SUCCESS;
        } catch (LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->releaseExclusive($projection);
        }
    }

    private function verify(ReportingProjectionRebuilder $rebuilder, string $projection, ?string $tenantId): int
    {
        $drift = $rebuilder->verify($projection, $tenantId);

        $this->info(sprintf(
            'Verified [%s]: %d missing, %d extra, %d mismatched row(s).',
            $projection,
            $drift->missingCount,
            $drift->extraCount,
            $drift->mismatchedCount,
        ));

        return $drift->isClean() ? self::SUCCESS : self::FAILURE;
    }
}
