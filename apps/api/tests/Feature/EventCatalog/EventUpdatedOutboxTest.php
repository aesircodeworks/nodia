<?php

declare(strict_types=1);

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\SeatMap;
use App\EventCatalog\Models\Venue;
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
 * Stage-05a plan, task breakdown item 5: EventUpdated producer on
 * UpdateEvent, mirroring tests/Feature/EventCatalog/EventCreatedOutboxTest.php.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->withHeaders([
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, [Capability::EventsView, Capability::EventsManage]),
        'X-Tenant-Id' => $this->tenantId,
    ]);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('outbox_deliveries')->where('tenant_id', $this->tenantId)->delete();
        DB::table('outbox_events')->where('tenant_id', $this->tenantId)->delete();
        // events before seat_maps: events.seat_map_id is on delete
        // restrict (stage-05b plan, Data model).
        DB::table('events')->where('tenant_id', $this->tenantId)->delete();
        DB::table('seat_maps')->where('tenant_id', $this->tenantId)->delete();
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
function eventUpdatedRow(string $tenantId, array $attributes = []): Event
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Event::factory()->create(['tenant_id' => $tenantId, ...$attributes]),
    );
}

it('persists exactly one EventUpdated outbox row on a successful PATCH over HTTP', function () {
    $event = eventUpdatedRow($this->tenantId);
    $correlationId = 'event-updated-correlation-'.Str::uuid7()->toString();

    $response = $this->withHeaders(['X-Correlation-Id' => $correlationId])
        ->patchJson('/v1/events/'.$event->id, ['timezone' => 'America/Chicago']);

    $response->assertOk();

    $rows = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventUpdated')->get(),
    );

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    expect($row->type)->toBe('EventUpdated')
        ->and($row->aggregate_type)->toBe('event')
        ->and($row->aggregate_id)->toBe($event->id)
        ->and($row->tenant_id)->toBe($this->tenantId)
        ->and($row->correlation_id)->toBe($correlationId)
        ->and($row->payload)->toBe(['event_id' => $event->id]);
});

it('records nothing when the update request fails validation', function () {
    $event = eventUpdatedRow($this->tenantId);

    $this->patchJson('/v1/events/'.$event->id, ['venue_id' => (string) Str::uuid7()])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'request.validation_failed');

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventUpdated')->count(),
    );

    expect($count)->toBe(0);
});

it('records nothing when the target event does not exist', function () {
    $this->patchJson('/v1/events/'.Str::uuid7(), ['timezone' => 'UTC'])
        ->assertNotFound()
        ->assertJsonPath('code', 'request.not_found');

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventUpdated')->count(),
    );

    expect($count)->toBe(0);
});

it('persists exactly one EventUpdated outbox row when only seat_map_id changes', function () {
    $venue = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Venue::factory()->create(['tenant_id' => $this->tenantId]),
    );
    $seatMap = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => SeatMap::factory()->create(['tenant_id' => $this->tenantId, 'venue_id' => $venue->id]),
    );
    $event = eventUpdatedRow($this->tenantId, [
        'is_virtual' => false, 'venue_id' => $venue->id, 'virtual_event_url' => null,
    ]);

    $response = $this->patchJson('/v1/events/'.$event->id, ['seat_map_id' => $seatMap->id]);

    $response->assertOk();

    $rows = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventUpdated')->get(),
    );

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    expect($row->aggregate_id)->toBe($event->id)
        ->and($row->tenant_id)->toBe($this->tenantId)
        ->and($row->payload)->toBe(['event_id' => $event->id]);
});

it('records nothing when the target event is canceled', function () {
    $event = eventUpdatedRow($this->tenantId, ['status' => EventStatus::Canceled]);

    $this->patchJson('/v1/events/'.$event->id, ['timezone' => 'UTC'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'catalog.event_immutable');

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventUpdated')->count(),
    );

    expect($count)->toBe(0);
});
