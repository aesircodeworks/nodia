<?php

namespace App\Console\Commands;

use App\Inventory\Actions\ReleaseExpiredHolds;
use Illuminate\Console\Command;

/**
 * Scheduled expiry sweep for active holds past their TTL (stage-06 plan,
 * Slice 3, task breakdown item 6). Runs every minute via the application
 * schedule; see bootstrap/app.php.
 */
class ReleaseExpiredHoldsCommand extends Command
{
    protected $signature = 'holds:release-expired';

    protected $description = 'Expire active holds past their expires_at and release their inventory';

    public function handle(ReleaseExpiredHolds $releaseExpiredHolds): int
    {
        $expired = $releaseExpiredHolds();

        $this->info("Expired {$expired} hold(s).");

        return self::SUCCESS;
    }
}
