<?php

namespace App\Console\Commands;

use App\Payments\Actions\ReconcileNamedOrders;
use App\Payments\Support\ReconcileNamedOrdersSummary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Support-safe, audited manual poll of named awaiting_payment orders
 * (stage-12 plan, Slice 5, task breakdown item 12; system-design 13).
 * Named payments:reconcile-orders, not the plan's literal
 * payments:reconcile: that signature is already the Stage 8a automatic
 * sweep (App\Console\Commands\ReconcilePendingPaymentsCommand, scheduled
 * every five minutes in bootstrap/app.php) -- see this task's journal
 * entry for the deviation.
 *
 * Dry-run by default; --execute polls the gateway for exactly the named
 * orders' own initiated payments and applies the same conditional-UPDATE
 * transitions the automatic sweep and the webhook path already use, so a
 * double run is harmless. Every invocation, dry-run or executed, is
 * activity-logged under the sentinel platform tenant naming the
 * operator, the arguments, and the matched/resolved counts.
 */
class ReconcileNamedOrdersCommand extends Command
{
    protected $signature = 'payments:reconcile-orders
                            {--operator= : Name of the operator running this command; required}
                            {--order=* : Order ID to reconcile; repeatable, required unless --tenant is given}
                            {--tenant= : Tenant ID whose awaiting_payment orders to reconcile; required unless --order is given}
                            {--before= : Only consider orders created at or before this instant (ISO-8601); required}
                            {--execute : Poll the gateway and apply transitions instead of only listing candidates}';

    protected $description = 'Dry-run (default) or poll named awaiting_payment orders\' initiated payments against the gateway';

    public function handle(ReconcileNamedOrders $reconcile): int
    {
        $operator = trim((string) $this->option('operator'));

        if ($operator === '') {
            $this->components->error('The --operator option is required.');

            return self::FAILURE;
        }

        $orderIds = array_values(array_filter(
            array_map(static fn (string $id): string => trim($id), (array) $this->option('order')),
            static fn (string $id): bool => $id !== '',
        ));

        $tenantOption = $this->option('tenant');
        $tenantId = is_string($tenantOption) && trim($tenantOption) !== '' ? trim($tenantOption) : null;

        if ($orderIds === [] && $tenantId === null) {
            $this->components->error('Either --order or --tenant is required.');

            return self::FAILURE;
        }

        $beforeOption = $this->option('before');

        if (! is_string($beforeOption) || trim($beforeOption) === '') {
            $this->components->error('The --before option is required.');

            return self::FAILURE;
        }

        try {
            $before = Date::parse($beforeOption);
        } catch (Throwable) {
            $this->components->error('The --before option must be a valid date/time.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');

        $summary = $reconcile->reconcile($operator, $execute, $orderIds, $tenantId, $before);

        $this->report($summary, $execute);

        return self::SUCCESS;
    }

    private function report(ReconcileNamedOrdersSummary $summary, bool $execute): void
    {
        foreach ($summary->orders as $order) {
            $this->line("  order {$order->id} (tenant: {$order->tenantId}, created_at: {$order->createdAt})");
        }

        if ($execute) {
            $this->info(sprintf(
                'Resolved %d of %d matching awaiting_payment order(s).',
                $summary->resolved,
                $summary->orders->count(),
            ));

            return;
        }

        $this->info(sprintf(
            'Dry run: %d matching awaiting_payment order(s) found. Re-run with --execute to poll them.',
            $summary->orders->count(),
        ));
    }
}
