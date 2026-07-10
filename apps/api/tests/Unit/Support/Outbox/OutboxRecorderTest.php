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
 * Stage-04 plan, Slice 1 unit tests for the recording API: outside a
 * transaction throws; unregistered type throws; occurred_at from the fake
 * clock; correlation_id from the request-scoped binding or generated
 * UUIDv7; payload serializes snake_case.
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

function fixtureEvent(string $tenantId, ?string $type = null): FixtureDomainEvent
{
    $aggregateId = Str::uuid7()->toString();

    return new FixtureDomainEvent(
        tenantId: $tenantId,
        aggregateId: $aggregateId,
        payload: new FixtureDomainEventPayload($aggregateId, 'Hello World'),
        type: $type ?? FixtureDomainEvent::TYPE,
    );
}

it('throws when recording outside any database transaction', function () {
    expect(fn () => app(OutboxRecorder::class)->record(fixtureEvent($this->tenantId)))
        ->toThrow(LogicException::class, 'inside an open database transaction');
});

it('throws when the event type is not in the registered set', function () {
    $event = fixtureEvent($this->tenantId, type: 'UnregisteredTestEvent');

    expect(fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(OutboxRecorder::class)->record($event),
    ))->toThrow(LogicException::class, 'UnregisteredTestEvent');
});

it('sets occurred_at to UTC from the fake clock', function () {
    $this->freezeTime();
    $frozen = now();

    $row = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(OutboxRecorder::class)->record(fixtureEvent($this->tenantId)),
    );

    // timestampTz columns store second precision; compare unix seconds and
    // timezone rather than microsecond-equalTo after the round trip.
    expect($row->occurred_at->getTimestamp())->toBe($frozen->getTimestamp())
        ->and($row->occurred_at->utcOffset())->toBe(0);
});

it('uses the request-scoped correlation id when present', function () {
    app(CorrelationId::class)->set('client-provided-correlation');

    $row = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(OutboxRecorder::class)->record(fixtureEvent($this->tenantId)),
    );

    expect($row->correlation_id)->toBe('client-provided-correlation');
});

it('generates a UUIDv7 correlation id when none is bound', function () {
    expect(app(CorrelationId::class)->has())->toBeFalse();

    $row = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(OutboxRecorder::class)->record(fixtureEvent($this->tenantId)),
    );

    expect($row->correlation_id)
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});

it('serializes the payload to snake_case json', function () {
    $event = fixtureEvent($this->tenantId);

    $row = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(OutboxRecorder::class)->record($event),
    );

    expect($row->payload)->toBe([
        'aggregate_id' => $event->aggregateId(),
        'display_name' => 'Hello World',
    ])
        ->and(array_key_exists('displayName', $row->payload))->toBeFalse();
});

it('persists the full envelope fields on a successful record', function () {
    app(CorrelationId::class)->set('envelope-correlation');
    $this->freezeTime();
    $event = fixtureEvent($this->tenantId);

    $row = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(OutboxRecorder::class)->record($event),
    );

    expect($row)->toBeInstanceOf(OutboxEvent::class)
        ->and(Str::isUuid($row->id))->toBeTrue()
        ->and($row->type)->toBe(FixtureDomainEvent::TYPE)
        ->and($row->tenant_id)->toBe($this->tenantId)
        ->and($row->aggregate_type)->toBe('fixture')
        ->and($row->aggregate_id)->toBe($event->aggregateId())
        ->and($row->correlation_id)->toBe('envelope-correlation')
        ->and($row->sequence)->toBeInt()
        ->and($row->sequence)->toBeGreaterThan(0);
});
