<?php

declare(strict_types=1);

use App\Support\Outbox\Models\OutboxEvent;
use Illuminate\Support\Str;

/*
 * Stage-04 plan, Slice 1: the model exposes no update or delete path;
 * append-only is an application invariant enforced on the model.
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
