<?php

namespace App\Console\Commands;

use App\Inventory\Actions\RunGatekeeperTick;
use Illuminate\Console\Command;

/**
 * Scheduled admission tick for the on-sale waiting room (stage-10 plan,
 * task breakdown item 8). Runs sub-minute via the application schedule;
 * see bootstrap/app.php for the cadence and the sub-minute-support note.
 */
class GatekeeperCommand extends Command
{
    protected $signature = 'onsale:gatekeeper';

    protected $description = 'Admit waiting-room entrants into checkout at each flagged event\'s configured admission rate';

    public function handle(RunGatekeeperTick $tick): int
    {
        $admitted = $tick();

        $this->info("Admitted {$admitted} entrant(s).");

        return self::SUCCESS;
    }
}
