<?php

namespace App\Console\Commands;

use App\Payments\Actions\ReconcilePendingPayments;
use Illuminate\Console\Command;

class ReconcilePendingPaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Query the gateway for initiated payments past the grace period and apply the missed transitions';

    public function handle(ReconcilePendingPayments $reconcile): int
    {
        $resolved = $reconcile();

        $this->info("Resolved {$resolved} payment(s).");

        return self::SUCCESS;
    }
}
