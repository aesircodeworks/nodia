<?php

namespace App\Console\Commands;

use App\Support\Archive\Actions\ArchiveActivityLog;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Scheduled archive-then-prune sweep for activity_log rows past the
 * configured retention window (stage-12 plan, Slice 3, task breakdown
 * item 9; system-design 14.2, 14.3). Runs daily via the application
 * schedule; see bootstrap/app.php and config/retention.php for the
 * window.
 */
class ArchiveActivityLogCommand extends Command
{
    protected $signature = 'activity-log:archive';

    protected $description = 'Archive activity_log rows past the configured retention window to object storage, then delete them once the upload checksum verifies';

    public function handle(ArchiveActivityLog $archive): int
    {
        try {
            $archived = $archive();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Archived and deleted {$archived} activity log row(s).");

        return self::SUCCESS;
    }
}
