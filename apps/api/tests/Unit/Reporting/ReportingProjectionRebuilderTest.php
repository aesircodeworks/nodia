<?php

declare(strict_types=1);

use App\Reporting\Support\Rebuild\ReportingProjectionRebuilder;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\Outbox\FixtureDomainEvent;
use Tests\Support\Outbox\IdempotentTestSubscriber;
use Tests\Support\Outbox\RebuildableTestSubscriber;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-11 plan, task 13 Unit test (TDD sequencing Slice 7: "the
 * rebuild reads through the replay primitive in sequence order and
 * respects the stability window"). Proves
 * ReportingProjectionRebuilder::eventsFor() delegates entirely to
 * App\Support\Outbox\OutboxReplay::eventsFor() rather than
 * reimplementing sequencing or the stability window itself: this test
 * exercises real outbox_events rows through the real primitive (Stage
 * 4's own sequencing and stability-window behavior is already proven
 * directly in tests/Unit/Support/Outbox/OutboxReplayTest.php; this test
 * is not a second proof of that primitive, only of the delegation).
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    app()->forgetInstance(SubscriberRegistry::class);

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    config()->set('outbox.stability_window_seconds', 5);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('outbox_events')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    app()->forgetInstance(SubscriberRegistry::class);
    app()->forgetScopedInstances();
});

function plantRebuildUnitEvent(string $tenantId, string $type = FixtureDomainEvent::TYPE): OutboxEvent
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $type): OutboxEvent {
        $event = OutboxEvent::query()->create([
            'type' => $type,
            'tenant_id' => $tenantId,
            'aggregate_type' => 'fixture',
            'aggregate_id' => Str::uuid7()->toString(),
            'correlation_id' => 'rebuild-unit-correlation',
            'occurred_at' => now(),
            'payload' => ['source' => 'rebuild-unit'],
        ]);
        // sequence is a generated identity column; refresh so callers see it.
        $event->refresh();

        return $event;
    });
}

it('reads only events past the stability window, in ascending sequence order', function (): void {
    registerRebuildableTestSubscriber();

    $this->freezeTime();

    $first = plantRebuildUnitEvent($this->tenantId);
    $second = plantRebuildUnitEvent($this->tenantId);

    expect($second->sequence)->toBeGreaterThan($first->sequence);

    $this->travel(config()->integer('outbox.stability_window_seconds') + 1)->seconds();

    $stillWithinWindow = plantRebuildUnitEvent($this->tenantId);

    $events = app(ReportingProjectionRebuilder::class)->eventsFor(RebuildableTestSubscriber::NAME);

    expect($events->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($events->pluck('sequence')->map(fn ($sequence): int => (int) $sequence)->all())->toBe([
            (int) $first->sequence,
            (int) $second->sequence,
        ])
        ->and(in_array($stillWithinWindow->id, $events->pluck('id')->all(), true))->toBeFalse();
});

it('filters by the projection own registered event types, not every outbox type', function (): void {
    registerRebuildableTestSubscriber();

    $this->freezeTime();

    $matched = plantRebuildUnitEvent($this->tenantId, FixtureDomainEvent::TYPE);
    $ignored = plantRebuildUnitEvent($this->tenantId, 'OtherFixtureEvent');

    $this->travel(config()->integer('outbox.stability_window_seconds') + 1)->seconds();

    $events = app(ReportingProjectionRebuilder::class)->eventsFor(RebuildableTestSubscriber::NAME);

    expect($events->pluck('id')->all())->toBe([$matched->id])
        ->and(in_array($ignored->id, $events->pluck('id')->all(), true))->toBeFalse();
});

it('throws when the projection name is not a registered subscriber', function (): void {
    expect(fn () => app(ReportingProjectionRebuilder::class)->eventsFor('missing_projection'))
        ->toThrow(LogicException::class);
});

it('throws when the registered subscriber is not a rebuildable projection', function (): void {
    registerIdempotentOutboxSubscriber();

    expect(fn () => app(ReportingProjectionRebuilder::class)->eventsFor(IdempotentTestSubscriber::NAME))
        ->toThrow(LogicException::class, 'is not a rebuildable reporting projection');
});
