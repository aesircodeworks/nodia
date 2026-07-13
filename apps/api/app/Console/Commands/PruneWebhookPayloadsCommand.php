<?php

namespace App\Console\Commands;

use App\Payments\Actions\PruneWebhookPayloads;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Scheduled retention sweep for raw webhook payloads (stage-12 plan,
 * Slice 3, task breakdown item 7; system-design 14.3). Runs daily via
 * the application schedule; see bootstrap/app.php and
 * config/retention.php for the window.
 */
class PruneWebhookPayloadsCommand extends Command
{
    protected $signature = 'webhooks:prune-payloads';

    protected $description = 'Null out gateway webhook payloads past the configured retention window, keeping the row and its gateway event id';

    public function handle(PruneWebhookPayloads $prune): int
    {
        try {
            $pruned = $prune();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Pruned {$pruned} webhook payload(s).");

        return self::SUCCESS;
    }
}
