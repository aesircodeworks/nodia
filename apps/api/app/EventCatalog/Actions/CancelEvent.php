<?php

namespace App\EventCatalog\Actions;

use App\EventCatalog\Data\EventData;
use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Events\EventCanceled;
use App\EventCatalog\Exceptions\EventNotCancelableException;
use App\EventCatalog\Models\Event;
use App\Support\Outbox\OutboxRecorder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * POST /v1/events/{event}/cancel (stage-05a plan, task breakdown item
 * 9). Cancel is legal from both draft and published (plan Risks: "Cancel
 * semantics"), so the guard is one conditional UPDATE matching either
 * source status, checked by affected-row count, never a read-then-write
 * existence check (master plan test-first rule 2; CLAUDE.md). This is
 * why a plain "UPDATE ... WHERE status = ? RETURNING ..." (PublishEvent's
 * shape) will not do here: EventCanceled's payload carries prior_status
 * (plan Domain events table), and a single guard covering two possible
 * source statuses cannot tell which one actually matched from an
 * ordinary RETURNING clause, which only ever exposes the row's
 * post-UPDATE values.
 *
 * The publish-versus-cancel race this guard must survive (plan TDD
 * sequencing, Slice 4) is exactly the case that breaks a naive read of
 * "prior status": if this event's status column is mid-flight from
 * draft to published in a concurrent, not-yet-committed publish
 * transaction, Postgres blocks this UPDATE on the row lock, then
 * re-evaluates the WHERE clause once that transaction commits
 * (READ COMMITTED's EvalPlanQual). A prior_status read from the
 * already-bound $event argument (loaded before this call, at route
 * model binding time) would then be stale: draft, when the row was
 * actually published a moment before this cancel actually applied.
 *
 * The fix, verified directly against this project's own PostgreSQL 17
 * container before relying on it (two manual overlapping transactions,
 * one holding an uncommitted UPDATE while the other ran this exact
 * statement shape and blocked, then unblocked once the first committed):
 * a data-modifying CTE whose own SELECT ... FOR UPDATE takes the row
 * lock first. A plain (non-FOR-UPDATE) CTE reads the transaction's
 * original snapshot and does not see the row that becomes visible only
 * once the blocking transaction commits, reintroducing the same
 * staleness; FOR UPDATE forces Postgres to block on the same row lock
 * the outer UPDATE would anyway, then re-fetch the just-committed row
 * once unblocked, exactly like the outer UPDATE's own EvalPlanQual
 * re-check. Joining the outer UPDATE against that CTE and returning the
 * CTE's own column captures the true prior status regardless of whether
 * this call was ever blocked. This one statement is still the sole
 * conditional write: the WHERE clause on the outer UPDATE is the only
 * place the transition is decided, gated by whether a row comes back at
 * all (affected-row count, here "count($rows) === 1" since RETURNING
 * emits one row per row actually updated).
 */
final class CancelEvent
{
    public function __construct(private readonly OutboxRecorder $outbox) {}

    public function __invoke(Event $event): EventData
    {
        $canceledAt = Date::now();

        /** @var list<object{prior_status: string}> $rows */
        $rows = DB::select(
            <<<'SQL'
            with prior as (
                select id, status from events where id = ? for update
            )
            update events
            set status = ?
            from prior
            where events.id = prior.id and prior.status in (?, ?)
            returning prior.status as prior_status
            SQL,
            [
                $event->id,
                EventStatus::Canceled->value,
                EventStatus::Draft->value,
                EventStatus::Published->value,
            ],
        );

        if (count($rows) !== 1) {
            throw EventNotCancelableException::forId($event->id);
        }

        $priorStatus = EventStatus::from($rows[0]->prior_status);

        $event = $event->fresh();

        $this->outbox->record(EventCanceled::fromEvent($event, $priorStatus, $canceledAt));

        return EventData::fromModel($event);
    }
}
