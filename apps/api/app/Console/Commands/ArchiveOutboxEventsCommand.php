<?php

namespace App\Console\Commands;

use App\Support\Archive\Actions\ArchiveOutboxEvents;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Scheduled archive-then-prune sweep for outbox_events rows past the
 * configured retention window (stage-12 plan, Slice 4, task breakdown
 * item 10; system-design 9.1). Runs daily via the application schedule;
 * see bootstrap/app.php and config/retention.php for the window and the
 * per-run batch size.
 */
class ArchiveOutboxEventsCommand extends Command
{
    protected $signature = 'outbox:archive';

    protected $description = 'Archive outbox_events rows past the configured retention window to object storage, then delete them once the upload checksum verifies';

    public function handle(ArchiveOutboxEvents $archive): int
    {
        try {
            $archived = $archive();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Archived and deleted {$archived} outbox event row(s).");

        return self::SUCCESS;
    }
}
