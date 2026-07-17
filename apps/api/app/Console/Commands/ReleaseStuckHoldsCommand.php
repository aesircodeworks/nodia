<?php

namespace App\Console\Commands;

use App\Inventory\Actions\ReleaseStuckHolds;
use App\Inventory\Support\ReleaseStuckHoldsSummary;
use Illuminate\Console\Command;

/**
 * Support-safe, audited manual release of holds stuck status active past
 * their expires_at (stage-12 plan, Slice 5, task breakdown item 13;
 * system-design 13). Named holds:release-stuck, distinct from the
 * automatic every-minute sweeper (holds:release-expired,
 * App\Console\Commands\ReleaseExpiredHoldsCommand, stage-06) the same
 * way outbox:replay-failed and payments:reconcile-orders already keep
 * their own manual and automatic siblings apart.
 *
 * Dry-run by default and safe to run unbounded (a read-only listing);
 * --execute additionally releases every matched hold through the Stage 6
 * ReleaseHold Action and refuses to run unbounded (no --tenant and no
 * --all-tenants), so an operator cannot accidentally release every stuck
 * hold on the platform by omission. Every invocation, dry-run or
 * executed, is activity-logged under the sentinel platform tenant naming
 * the operator, the arguments, and the matched/released counts.
 */
class ReleaseStuckHoldsCommand extends Command
{
    protected $signature = 'holds:release-stuck
                            {--operator= : Name of the operator running this command; required}
                            {--tenant=* : Tenant ID to scope to; repeatable}
                            {--all-tenants : Explicitly run across every tenant; required with --execute unless --tenant is given}
                            {--execute : Release matched holds instead of only listing them}';

    protected $description = 'Dry-run (default) or release holds stuck active past their expires_at';

    public function handle(ReleaseStuckHolds $releaseStuckHolds): int
    {
        $operator = trim((string) $this->option('operator'));

        if ($operator === '') {
            $this->components->error('The --operator option is required.');

            return self::FAILURE;
        }

        $tenantIds = array_values(array_filter(
            array_map(static fn (string $id): string => trim($id), (array) $this->option('tenant')),
            static fn (string $id): bool => $id !== '',
        ));

        $allTenants = (bool) $this->option('all-tenants');
        $execute = (bool) $this->option('execute');

        if ($execute && $tenantIds === [] && ! $allTenants) {
            $this->components->error('Refusing an unbounded --execute: pass --tenant or --all-tenants.');

            return self::FAILURE;
        }

        $summary = $releaseStuckHolds->release($operator, $execute, $allTenants ? [] : $tenantIds);

        $this->report($summary, $execute);

        return self::SUCCESS;
    }

    private function report(ReleaseStuckHoldsSummary $summary, bool $execute): void
    {
        foreach ($summary->holds as $hold) {
            $this->line("  hold {$hold->id} (tenant: {$hold->tenant_id}, event: {$hold->event_id}, expires_at: {$hold->expires_at})");
        }

        if ($execute) {
            $this->info(sprintf(
                'Released %d of %d matching active, expired hold(s).',
                $summary->released,
                $summary->holds->count(),
            ));

            return;
        }

        $this->info(sprintf(
            'Dry run: %d matching active, expired hold(s) found. Re-run with --execute to release them.',
            $summary->holds->count(),
        ));
    }
}
