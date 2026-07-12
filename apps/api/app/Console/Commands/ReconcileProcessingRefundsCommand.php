<?php

namespace App\Console\Commands;

use App\Payments\Actions\ReconcileProcessingRefunds;
use Illuminate\Console\Command;

class ReconcileProcessingRefundsCommand extends Command
{
    protected $signature = 'payments:reconcile-refunds';

    protected $description = 'Query the gateway for processing refunds past the grace period and apply the missed transitions';

    public function handle(ReconcileProcessingRefunds $reconcile): int
    {
        $resolved = $reconcile();

        $this->info("Resolved {$resolved} refund(s).");

        return self::SUCCESS;
    }
}
