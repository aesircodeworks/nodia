<?php

namespace App\Console\Commands;

use App\Support\Outbox\OutboxFailedReplay;
use App\Support\Outbox\OutboxFailedReplaySummary;
use Illuminate\Console\Command;

/**
 * Support-safe, audited manual replay for the outbox's dead-lettered
 * ProcessOutboxDelivery jobs and any stranded pending deliveries
 * (stage-12 plan, Slice 5, task breakdown item 11; system-design 13).
 * Dry-run by default; --execute re-enqueues each candidate exactly once
 * through the same idempotent ProcessOutboxDelivery path the standing
 * outbox:sweep sweeper and a live delivery already use, so an accidental
 * double replay is harmless (event-conventions consumer idempotence).
 * Every invocation, dry-run or executed, is activity-logged under the
 * sentinel platform tenant naming the operator, the arguments, and the
 * matched/re-enqueued counts.
 */
class ReplayFailedOutboxCommand extends Command
{
    protected $signature = 'outbox:replay-failed
                            {--operator= : Name of the operator running this command; required}
                            {--execute : Re-enqueue matching failed jobs and stranded deliveries instead of only listing them}';

    protected $description = 'Dry-run (default) or re-enqueue the outbox\'s dead-lettered failed jobs and stranded pending deliveries';

    public function handle(OutboxFailedReplay $replay): int
    {
        $operator = trim((string) $this->option('operator'));

        if ($operator === '') {
            $this->components->error('The --operator option is required.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');

        $summary = $replay->replay($operator, $execute);

        $this->report($summary, $execute);

        return self::SUCCESS;
    }

    private function report(OutboxFailedReplaySummary $summary, bool $execute): void
    {
        foreach ($summary->failedJobs as $job) {
            $this->line("  failed job {$job->id} (queue: {$job->queue}, failed_at: {$job->failed_at})");
        }

        foreach ($summary->strandedDeliveries as $delivery) {
            $this->line("  stranded delivery {$delivery->id} (event: {$delivery->outbox_event_id}, subscriber: {$delivery->subscriber})");
        }

        if ($execute) {
            $this->info(sprintf(
                'Re-enqueued %d matching failed job(s) and %d stranded delivery(ies).',
                $summary->failedJobsReenqueued,
                $summary->strandedDeliveriesReenqueued,
            ));

            return;
        }

        $this->info(sprintf(
            'Dry run: %d matching failed job(s) and %d stranded delivery(ies) found. Re-run with --execute to re-enqueue them.',
            $summary->failedJobs->count(),
            $summary->strandedDeliveries->count(),
        ));
    }
}
