<?php

declare(strict_types=1);

use App\Support\Correlation\CorrelationId;
use App\Support\Outbox\EventTypeRegistry;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OutboxRecorder;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\Outbox\FixtureDomainEvent;
use Tests\Support\Outbox\FixtureDomainEventPayload;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-04 plan, Slice 1 feature tests (real PostgreSQL): recording inside
 * a rolled-back transaction leaves no outbox row; recording inside a
 * committed transaction persists exactly one row with the full envelope
 * (the first mandated test from the master plan's Stage 4 line).
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    app(EventTypeRegistry::class)->register(FixtureDomainEvent::TYPE);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('outbox_events')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    app()->forgetScopedInstances();
});

function recordingFixtureEvent(string $tenantId): FixtureDomainEvent
{
    $aggregateId = Str::uuid7()->toString();

    return new FixtureDomainEvent(
        tenantId: $tenantId,
        aggregateId: $aggregateId,
        payload: new FixtureDomainEventPayload($aggregateId, 'Feature Fixture'),
    );
}

it('leaves no outbox row when the producing transaction rolls back', function () {
    $event = recordingFixtureEvent($this->tenantId);

    try {
        app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($event): void {
            app(OutboxRecorder::class)->record($event);
            throw new RuntimeException('force rollback after record');
        });
    } catch (RuntimeException) {
        // expected
    }

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('aggregate_id', $event->aggregateId())->count(),
    );

    expect($count)->toBe(0);
});

it('persists exactly one row with the full envelope when the producing transaction commits', function () {
    app(CorrelationId::class)->set('feature-correlation-id');
    $this->freezeTime();
    $frozen = now();
    $event = recordingFixtureEvent($this->tenantId);

    $recorded = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(OutboxRecorder::class)->record($event),
    );

    $rows = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('aggregate_id', $event->aggregateId())->get(),
    );

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    expect($row->id)->toBe($recorded->id)
        ->and(Str::isUuid($row->id))->toBeTrue()
        ->and($row->type)->toBe(FixtureDomainEvent::TYPE)
        ->and($row->tenant_id)->toBe($this->tenantId)
        ->and($row->aggregate_type)->toBe('fixture')
        ->and($row->aggregate_id)->toBe($event->aggregateId())
        ->and($row->correlation_id)->toBe('feature-correlation-id')
        ->and($row->occurred_at->getTimestamp())->toBe($frozen->getTimestamp())
        ->and($row->occurred_at->utcOffset())->toBe(0)
        ->and($row->payload)->toBe([
            'aggregate_id' => $event->aggregateId(),
            'display_name' => 'Feature Fixture',
        ])
        ->and($row->sequence)->toBeInt()
        ->and($row->sequence)->toBeGreaterThan(0);
});
