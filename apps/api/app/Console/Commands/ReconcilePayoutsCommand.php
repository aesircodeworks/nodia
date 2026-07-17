<?php

namespace App\Console\Commands;

use App\Payments\Actions\ReconcilePayouts;
use Illuminate\Console\Command;

class ReconcilePayoutsCommand extends Command
{
    protected $signature = 'payments:reconcile-payouts';

    protected $description = 'Poll every gateway for payouts and reconcile them against the mirror, creating any missed by webhooks';

    public function handle(ReconcilePayouts $reconcile): int
    {
        $resolved = $reconcile();

        $this->info("Reconciled {$resolved} payout(s).");

        return self::SUCCESS;
    }
}
