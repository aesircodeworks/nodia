<?php

namespace App\Console\Commands;

use App\Identity\Actions\PruneDataSubjectExportAttachments;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Scheduled retention sweep for data subject export attachments
 * (stage-12 plan, Slice 3, task breakdown item 7). Runs daily via the
 * application schedule; see bootstrap/app.php and config/retention.php
 * for the window.
 */
class PruneDataSubjectExportAttachmentsCommand extends Command
{
    protected $signature = 'data-subject-requests:prune-exports';

    protected $description = 'Delete data subject export attachments past the configured retention window, keeping the request row as audit trail';

    public function handle(PruneDataSubjectExportAttachments $prune): int
    {
        try {
            $pruned = $prune();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Pruned {$pruned} data subject export attachment(s).");

        return self::SUCCESS;
    }
}
