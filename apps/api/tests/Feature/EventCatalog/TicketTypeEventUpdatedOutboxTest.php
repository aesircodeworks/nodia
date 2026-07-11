<?php

declare(strict_types=1);

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
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
 * Stage-05a plan, task breakdown item 8: CreateTicketType and
 * UpdateTicketType both record EventUpdated for the parent event, since
 * the section 9.3 registry has no ticket-type event of its own (plan
 * Domain events table), mirroring
 * tests/Feature/EventCatalog/EventUpdatedOutboxTest.php's own structure.
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
        DB::table('ticket_type_inventory')->where('tenant_id', $this->tenantId)->delete();
        DB::table('ticket_types')->where('tenant_id', $this->tenantId)->delete();
        DB::table('events')->where('tenant_id', $this->tenantId)->delete();
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
function outboxTicketTypeEvent(string $tenantId, array $attributes = []): Event
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Event::factory()->create(['tenant_id' => $tenantId, ...$attributes]),
    );
}

/**
 * @param  array<string, mixed>  $attributes
 */
function outboxTicketTypeRow(string $tenantId, string $eventId, array $attributes = []): TicketType
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $eventId, ...$attributes]),
    );
}

it('persists exactly one EventUpdated outbox row for the parent event on a successful ticket type creation', function () {
    $event = outboxTicketTypeEvent($this->tenantId);
    $correlationId = 'ticket-type-created-correlation-'.Str::uuid7()->toString();

    $response = $this->withHeaders(['X-Correlation-Id' => $correlationId])
        ->postJson("/v1/events/{$event->id}/ticket-types", [
            'name' => 'General Admission',
            'price' => ['amount' => 5000, 'currency' => 'USD'],
            'sales_start' => null,
            'sales_end' => null,
        ]);

    $response->assertCreated();

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

it('records nothing for ticket type creation when the request fails validation', function () {
    $event = outboxTicketTypeEvent($this->tenantId);

    $this->postJson("/v1/events/{$event->id}/ticket-types", [
        'name' => 'General Admission',
        'price' => ['amount' => -100, 'currency' => 'USD'],
        'sales_start' => null,
        'sales_end' => null,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'request.validation_failed');

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventUpdated')->count(),
    );

    expect($count)->toBe(0);
});

it('records nothing for ticket type creation on a currency mismatch', function () {
    $event = outboxTicketTypeEvent($this->tenantId);

    $this->postJson("/v1/events/{$event->id}/ticket-types", [
        'name' => 'General Admission',
        'price' => ['amount' => 5000, 'currency' => 'EUR'],
        'sales_start' => null,
        'sales_end' => null,
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'catalog.currency_mismatch');

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventUpdated')->count(),
    );

    expect($count)->toBe(0);
});

it('records nothing for ticket type creation when the parent event is canceled', function () {
    $event = outboxTicketTypeEvent($this->tenantId, ['status' => EventStatus::Canceled]);

    $this->postJson("/v1/events/{$event->id}/ticket-types", [
        'name' => 'General Admission',
        'price' => ['amount' => 5000, 'currency' => 'USD'],
        'sales_start' => null,
        'sales_end' => null,
    ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'catalog.event_immutable');

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventUpdated')->count(),
    );

    expect($count)->toBe(0);
});

it('persists exactly one EventUpdated outbox row for the parent event on a successful ticket type update', function () {
    $event = outboxTicketTypeEvent($this->tenantId);
    $ticketType = outboxTicketTypeRow($this->tenantId, $event->id);
    $correlationId = 'ticket-type-updated-correlation-'.Str::uuid7()->toString();

    $response = $this->withHeaders(['X-Correlation-Id' => $correlationId])
        ->patchJson("/v1/ticket-types/{$ticketType->id}", ['name' => 'Renamed']);

    $response->assertOk();

    $rows = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventUpdated')->get(),
    );

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    expect($row->aggregate_id)->toBe($event->id)
        ->and($row->tenant_id)->toBe($this->tenantId)
        ->and($row->correlation_id)->toBe($correlationId)
        ->and($row->payload)->toBe(['event_id' => $event->id]);
});

it('records nothing for ticket type update when the target does not exist', function () {
    $this->patchJson('/v1/ticket-types/'.Str::uuid7(), ['name' => 'Renamed'])
        ->assertNotFound()
        ->assertJsonPath('code', 'request.not_found');

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventUpdated')->count(),
    );

    expect($count)->toBe(0);
});

it('records nothing for ticket type update on a currency mismatch', function () {
    $event = outboxTicketTypeEvent($this->tenantId);
    $ticketType = outboxTicketTypeRow($this->tenantId, $event->id);

    $this->patchJson("/v1/ticket-types/{$ticketType->id}", [
        'price' => ['amount' => 5000, 'currency' => 'EUR'],
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'catalog.currency_mismatch');

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventUpdated')->count(),
    );

    expect($count)->toBe(0);
});

it('records nothing for ticket type update when the parent event is canceled', function () {
    $event = outboxTicketTypeEvent($this->tenantId, ['status' => EventStatus::Canceled]);
    $ticketType = outboxTicketTypeRow($this->tenantId, $event->id);

    $this->patchJson("/v1/ticket-types/{$ticketType->id}", ['name' => 'Renamed'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'catalog.event_immutable');

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventUpdated')->count(),
    );

    expect($count)->toBe(0);
});
