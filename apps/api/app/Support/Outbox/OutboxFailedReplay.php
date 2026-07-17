<?php

namespace App\Support\Outbox;

use App\Support\Audit\ActivityLogger;
use App\Support\Outbox\Jobs\ProcessOutboxDelivery;
use App\Support\Outbox\Models\OutboxDelivery;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;

/**
 * The core of outbox:replay-failed (stage-12 plan, Slice 5, task
 * breakdown item 11): lists, and on request re-enqueues, the outbox's
 * dead-lettered ProcessOutboxDelivery jobs (failed_jobs, Stage 4's dead
 * letter table) alongside any stranded pending deliveries
 * (OutboxSweeper::findStranded, the same definition the standing
 * outbox:sweep sweeper already uses, so this command never invents a
 * second "stranded" that could drift from it).
 *
 * candidates() issues no writes at all: a dry-run investigation is safe
 * to run freely. replay() additionally re-enqueues on $execute and always
 * records one activity log entry under the sentinel platform tenant
 * naming the operator, the arguments, and the matched/re-enqueued
 * counts, whether or not $execute was set, so a dry-run run is itself
 * auditable (stage-12 plan, task breakdown item 6: "writes an activity
 * log entry naming the operator" is a property of the command, not just
 * of --execute).
 *
 * A failed job is re-enqueued exactly the way Laravel's own queue:retry
 * does (Illuminate\Queue\Console\RetryCommand::retryJob): the original
 * serialized payload is pushed back raw, attempts reset to zero, never
 * decoded on this side. tests/Architecture's security preset forbids
 * unserialize() anywhere under app/, so identifying a failed job's
 * (event, subscriber) pair to de-duplicate against OutboxSweeper's own
 * stranded-delivery list (below) reads the event id back as a plain
 * substring of the still-JSON-encoded payload rather than deserializing
 * the PHP command object embedded in it: the delivery job's constructor
 * arguments are always plain strings PHP's serializer writes out
 * literally (event-conventions: an outbox event id is a UUIDv7, globally
 * unique), so a substring match is exact in practice without ever
 * running unserialize.
 */
final readonly class OutboxFailedReplay
{
    public function __construct(
        private OutboxSweeper $sweeper,
        private FailedJobProviderInterface $failer,
        private TenantTransaction $transactions,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * A delivery whose job already failed and dead-lettered is itself
     * "stranded" by OutboxSweeper::findStranded()'s own definition
     * (pending, past grace, event past the stability window): nothing
     * about that delivery's last_enqueued_at distinguishes "never
     * dispatched" from "dispatched once and failed" (the sweeper's own
     * reenqueue() is the only writer of last_enqueued_at; a job that
     * fails on its original post-record dispatch, or on a Horizon-side
     * retry, never touches it). Excluding any stranded delivery whose
     * event id already appears among the matched failed jobs' own
     * payloads keeps the two candidate lists mutually exclusive, so the
     * same delivery is never listed, counted, or re-enqueued twice under
     * two different reasons.
     */
    public function candidates(): OutboxFailedReplaySummary
    {
        $failedJobs = $this->matchingFailedJobs();

        $strandedDeliveries = $this->sweeper->findStranded()
            ->reject(fn (OutboxDelivery $delivery): bool => $failedJobs->contains(
                fn (object $job): bool => str_contains((string) $job->payload, $delivery->outbox_event_id),
            ))
            ->values();

        return new OutboxFailedReplaySummary($failedJobs, $strandedDeliveries);
    }

    public function replay(string $operator, bool $execute): OutboxFailedReplaySummary
    {
        $candidates = $this->candidates();

        $failedJobsReenqueued = 0;
        $strandedDeliveriesReenqueued = 0;

        if ($execute) {
            foreach ($candidates->failedJobs as $job) {
                $this->retryFailedJob($job);
                $failedJobsReenqueued++;
            }

            foreach ($candidates->strandedDeliveries as $delivery) {
                if ($this->sweeper->reenqueue($delivery)) {
                    $strandedDeliveriesReenqueued++;
                }
            }
        }

        $summary = new OutboxFailedReplaySummary(
            failedJobs: $candidates->failedJobs,
            strandedDeliveries: $candidates->strandedDeliveries,
            failedJobsReenqueued: $failedJobsReenqueued,
            strandedDeliveriesReenqueued: $strandedDeliveriesReenqueued,
        );

        $this->transactions->asPlatform(function () use ($operator, $execute, $summary): void {
            $this->activityLogger->record(
                description: sprintf('outbox:replay-failed %s by %s', $execute ? 'executed' : 'previewed', $operator),
                event: 'outbox_replay_failed_invoked',
                properties: [
                    'operator' => $operator,
                    'execute' => $execute,
                    'failed_jobs_matched' => $summary->failedJobs->count(),
                    'stranded_deliveries_matched' => $summary->strandedDeliveries->count(),
                    'failed_jobs_reenqueued' => $summary->failedJobsReenqueued,
                    'stranded_deliveries_reenqueued' => $summary->strandedDeliveriesReenqueued,
                ],
            );
        });

        return $summary;
    }

    /**
     * @return Collection<int, object>
     */
    private function matchingFailedJobs(): Collection
    {
        return collect($this->failer->all())
            ->filter(fn (object $job): bool => $this->jobClass($job) === ProcessOutboxDelivery::class)
            ->values();
    }

    private function jobClass(object $job): ?string
    {
        $payload = json_decode((string) $job->payload, true);

        return is_array($payload) ? ($payload['displayName'] ?? null) : null;
    }

    /**
     * Mirrors Illuminate\Queue\Console\RetryCommand::retryJob() and
     * resetAttempts(): push the original payload back unread, with any
     * Redis-tracked attempts counter reset to zero so the replayed
     * attempt starts fresh against ProcessOutboxDelivery's own $tries.
     */
    private function retryFailedJob(object $job): void
    {
        Queue::connection($job->connection)->pushRaw(
            $this->resetAttempts((string) $job->payload),
            $job->queue,
        );

        $this->failer->forget($job->id);
    }

    private function resetAttempts(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded) || ! array_key_exists('attempts', $decoded)) {
            return $payload;
        }

        $decoded['attempts'] = 0;

        return json_encode($decoded, JSON_THROW_ON_ERROR);
    }
}
