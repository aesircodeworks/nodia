<?php

declare(strict_types=1);

use App\Support\Outbox\Models\OutboxEvent;
use Illuminate\Support\Str;

/*
 * Stage-04 plan, Slice 1: the model exposes no update or delete path;
 * append-only is an application invariant enforced on the model.
 *
 * Relaxed by the stage-12 plan, Slice 4, task breakdown item 10: a row
 * is never updated, and never deleted before the retention window or by
 * anything but the archiver (App\Support\Archive\Actions\
 * ArchiveOutboxEvents). That relaxation does not touch this guard or
 * these two cases: the archiver deletes verified rows through the query
 * builder directly (DB::table('outbox_events')), which never fires
 * Eloquent model events, so this LogicException still fires for every
 * ordinary application code path that reaches the model's own update()
 * or delete() (event-conventions; ArchiveActivityLog's identical
 * query-builder-bypass precedent from task breakdown item 9).
 */

it('throws LogicException on update', function () {
    $event = new OutboxEvent([
        'type' => 'FixtureEvent',
        'tenant_id' => Str::uuid7()->toString(),
        'aggregate_type' => 'fixture',
        'aggregate_id' => Str::uuid7()->toString(),
        'correlation_id' => 'corr',
        'occurred_at' => now(),
        'payload' => ['ok' => true],
    ]);
    $event->id = Str::uuid7()->toString();
    $event->exists = true;
    $event->syncOriginal();

    expect(fn () => $event->update(['type' => 'Changed']))
        ->toThrow(LogicException::class, 'append-only');
});

it('throws LogicException on delete', function () {
    $event = new OutboxEvent([
        'type' => 'FixtureEvent',
        'tenant_id' => Str::uuid7()->toString(),
        'aggregate_type' => 'fixture',
        'aggregate_id' => Str::uuid7()->toString(),
        'correlation_id' => 'corr',
        'occurred_at' => now(),
        'payload' => ['ok' => true],
    ]);
    $event->id = Str::uuid7()->toString();
    $event->exists = true;
    $event->syncOriginal();

    expect(fn () => $event->delete())
        ->toThrow(LogicException::class, 'append-only');
});
