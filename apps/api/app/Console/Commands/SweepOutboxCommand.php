<?php

namespace App\Console\Commands;

use App\Support\Outbox\OutboxSweeper;
use Illuminate\Console\Command;

/**
 * Scheduled reconciliation of stranded outbox deliveries (system-design
 * 9.2). Runs every minute via the application schedule; see
 * bootstrap/app.php and config/outbox.php for the grace and stability
 * windows.
 */
class SweepOutboxCommand extends Command
{
    protected $signature = 'outbox:sweep';

    protected $description = 'Re-enqueue stranded pending outbox deliveries past the grace window';

    public function handle(OutboxSweeper $sweeper): int
    {
        $requeued = $sweeper->sweep();

        $this->info("Re-enqueued {$requeued} stranded outbox delivery(ies).");

        return self::SUCCESS;
    }
}
