<?php

declare(strict_types=1);

use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OutboxReplay;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\Outbox\FixtureDomainEvent;
use Tests\Support\Outbox\ProjectionTestSubscriber;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-04 plan Slice 5 unit: replay filters by the subscriber's
 * subscribed types and accepts a starting sequence.
 */

const OTHER_FIXTURE_TYPE = 'OtherFixtureEvent';

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

function plantOutboxEvent(string $tenantId, string $type, string $aggregateId = ''): OutboxEvent
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $type, $aggregateId): OutboxEvent {
        $event = OutboxEvent::query()->create([
            'type' => $type,
            'tenant_id' => $tenantId,
            'aggregate_type' => 'fixture',
            'aggregate_id' => $aggregateId !== '' ? $aggregateId : Str::uuid7()->toString(),
            'correlation_id' => 'replay-unit-correlation',
            'occurred_at' => now(),
            'payload' => ['source' => 'replay-unit'],
        ]);
        // sequence is a generated identity column; refresh so callers see it.
        $event->refresh();

        return $event;
    });
}

it('filters replay by the subscriber subscribed types', function () {
    $subscriber = new ProjectionTestSubscriber;
    app(SubscriberRegistry::class)->register(
        ProjectionTestSubscriber::NAME,
        [FixtureDomainEvent::TYPE],
        $subscriber,
    );

    $this->freezeTime();

    $matched = plantOutboxEvent($this->tenantId, FixtureDomainEvent::TYPE);
    $ignored = plantOutboxEvent($this->tenantId, OTHER_FIXTURE_TYPE);

    $this->travel(config()->integer('outbox.stability_window_seconds') + 1)->seconds();

    $count = app(OutboxReplay::class)->replay(ProjectionTestSubscriber::NAME);

    expect($count)->toBe(1)
        ->and($subscriber->snapshot()['event_ids'])->toBe([$matched->id])
        ->and(in_array($ignored->id, $subscriber->snapshot()['event_ids'], true))->toBeFalse();
});

it('accepts an inclusive starting sequence', function () {
    $subscriber = new ProjectionTestSubscriber;
    app(SubscriberRegistry::class)->register(
        ProjectionTestSubscriber::NAME,
        [FixtureDomainEvent::TYPE],
        $subscriber,
    );

    $this->freezeTime();

    $first = plantOutboxEvent($this->tenantId, FixtureDomainEvent::TYPE);
    $second = plantOutboxEvent($this->tenantId, FixtureDomainEvent::TYPE);
    $third = plantOutboxEvent($this->tenantId, FixtureDomainEvent::TYPE);

    expect($second->sequence)->toBeGreaterThan($first->sequence)
        ->and($third->sequence)->toBeGreaterThan($second->sequence);

    $this->travel(config()->integer('outbox.stability_window_seconds') + 1)->seconds();

    $count = app(OutboxReplay::class)->replay(
        ProjectionTestSubscriber::NAME,
        fromSequence: (int) $second->sequence,
    );

    expect($count)->toBe(2)
        ->and($subscriber->visitOrder())->toBe([
            (int) $second->sequence,
            (int) $third->sequence,
        ])
        ->and($subscriber->snapshot()['event_ids'])->toEqualCanonicalizing([
            $second->id,
            $third->id,
        ]);
});

it('throws when the subscriber is not registered', function () {
    expect(fn () => app(OutboxReplay::class)->replay('missing_subscriber'))
        ->toThrow(LogicException::class, 'missing_subscriber');
});
