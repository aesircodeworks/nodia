<?php

declare(strict_types=1);

use App\EventCatalog\Actions\CancelEvent;
use App\EventCatalog\Actions\PublishEvent;
use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\Identity\Capability;
use App\Models\User;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\TenantStaff;

/*
 * Stage-05a plan, task breakdown item 9: EventPublished/EventCanceled
 * producers on PublishEvent/CancelEvent, mirroring
 * tests/Feature/EventCatalog/EventUpdatedOutboxTest.php's own structure.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->withHeaders([
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, [Capability::EventsView, Capability::EventsManage, Capability::EventsPublish]),
        'X-Tenant-Id' => $this->tenantId,
    ]);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('outbox_deliveries')->where('tenant_id', $this->tenantId)->delete();
        DB::table('outbox_events')->where('tenant_id', $this->tenantId)->delete();
        DB::table('events')->where('tenant_id', $this->tenantId)->delete();
        DB::table('venues')->where('tenant_id', $this->tenantId)->delete();
        DB::table('memberships')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    User::query()->delete();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function lifecycleOutboxEvent(string $tenantId, array $attributes = []): Event
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Event::factory()->create(['tenant_id' => $tenantId, ...$attributes]),
    );
}

it('persists exactly one EventPublished outbox row on a successful publish over HTTP', function () {
    $event = lifecycleOutboxEvent($this->tenantId, ['status' => EventStatus::Draft]);
    $correlationId = 'event-published-correlation-'.Str::uuid7()->toString();

    $this->withHeaders(['X-Correlation-Id' => $correlationId])
        ->postJson('/v1/events/'.$event->id.'/publish')
        ->assertOk();

    $rows = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventPublished')->get(),
    );

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    expect($row->type)->toBe('EventPublished')
        ->and($row->aggregate_type)->toBe('event')
        ->and($row->aggregate_id)->toBe($event->id)
        ->and($row->tenant_id)->toBe($this->tenantId)
        ->and($row->correlation_id)->toBe($correlationId)
        ->and($row->payload)->toHaveKeys(['event_id', 'published_at'])
        ->and($row->payload['event_id'])->toBe($event->id);
});

it('records nothing when publish is rejected as not publishable', function () {
    $event = lifecycleOutboxEvent($this->tenantId, ['status' => EventStatus::Published]);

    $this->postJson('/v1/events/'.$event->id.'/publish')
        ->assertStatus(409)
        ->assertJsonPath('code', 'catalog.event_not_publishable');

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventPublished')->count(),
    );

    expect($count)->toBe(0);
});

it('records nothing when the target event does not exist for publish', function () {
    $this->postJson('/v1/events/'.Str::uuid7().'/publish')
        ->assertNotFound()
        ->assertJsonPath('code', 'request.not_found');

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventPublished')->count(),
    );

    expect($count)->toBe(0);
});

it('leaves no EventPublished row when the producing transaction rolls back after recording', function () {
    $tenantId = $this->tenantId;
    $event = lifecycleOutboxEvent($tenantId, ['status' => EventStatus::Draft]);

    try {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($event): void {
            app(PublishEvent::class)($event);
            throw new RuntimeException('force rollback after EventPublished record');
        });
    } catch (RuntimeException) {
        // expected
    }

    $count = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventPublished')->count(),
    );

    expect($count)->toBe(0);
});

it('persists exactly one EventCanceled outbox row carrying prior_status on a successful cancel over HTTP', function () {
    $event = lifecycleOutboxEvent($this->tenantId, ['status' => EventStatus::Published]);
    $correlationId = 'event-canceled-correlation-'.Str::uuid7()->toString();

    $this->withHeaders(['X-Correlation-Id' => $correlationId])
        ->postJson('/v1/events/'.$event->id.'/cancel')
        ->assertOk();

    $rows = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventCanceled')->get(),
    );

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    expect($row->type)->toBe('EventCanceled')
        ->and($row->aggregate_type)->toBe('event')
        ->and($row->aggregate_id)->toBe($event->id)
        ->and($row->tenant_id)->toBe($this->tenantId)
        ->and($row->correlation_id)->toBe($correlationId)
        ->and($row->payload)->toHaveKeys(['event_id', 'canceled_at', 'prior_status'])
        ->and($row->payload['event_id'])->toBe($event->id)
        ->and($row->payload['prior_status'])->toBe('published');
});

it('records prior_status draft when canceling a draft event', function () {
    $event = lifecycleOutboxEvent($this->tenantId, ['status' => EventStatus::Draft]);

    $this->postJson('/v1/events/'.$event->id.'/cancel')->assertOk();

    $row = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventCanceled')->first(),
    );

    expect($row->payload['prior_status'])->toBe('draft');
});

it('records nothing when cancel is rejected as not cancelable', function () {
    $event = lifecycleOutboxEvent($this->tenantId, ['status' => EventStatus::Canceled]);

    $this->postJson('/v1/events/'.$event->id.'/cancel')
        ->assertStatus(409)
        ->assertJsonPath('code', 'catalog.event_not_cancelable');

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventCanceled')->count(),
    );

    expect($count)->toBe(0);
});

it('records nothing when the target event does not exist for cancel', function () {
    $this->postJson('/v1/events/'.Str::uuid7().'/cancel')
        ->assertNotFound()
        ->assertJsonPath('code', 'request.not_found');

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventCanceled')->count(),
    );

    expect($count)->toBe(0);
});

it('leaves no EventCanceled row when the producing transaction rolls back after recording', function () {
    $tenantId = $this->tenantId;
    $event = lifecycleOutboxEvent($tenantId, ['status' => EventStatus::Draft]);

    try {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($event): void {
            app(CancelEvent::class)($event);
            throw new RuntimeException('force rollback after EventCanceled record');
        });
    } catch (RuntimeException) {
        // expected
    }

    $count = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventCanceled')->count(),
    );

    expect($count)->toBe(0);
});
