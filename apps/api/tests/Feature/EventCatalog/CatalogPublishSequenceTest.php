<?php

use App\Identity\Capability;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\TenantStaff;

/*
 * Stage-05a plan, exit criteria 1 and 2: the full build-and-publish
 * sequence as a single feature test per path, one staff bearer holding
 * events.manage for the creates and events.publish for the transition.
 * Each step is already covered in isolation by VenueEndpointsTest,
 * EventEndpointsTest, TicketTypeEndpointsTest, and
 * EventLifecycleEndpointsTest; this file proves the chained sequence
 * over /v1 end to end.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->withHeaders([
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, [Capability::EventsManage, Capability::EventsPublish]),
        'X-Tenant-Id' => $this->tenantId,
    ]);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('outbox_deliveries')->where('tenant_id', $this->tenantId)->delete();
        DB::table('outbox_events')->where('tenant_id', $this->tenantId)->delete();
        DB::table('memberships')->where('tenant_id', $this->tenantId)->delete();
        DB::table('ticket_types')->where('tenant_id', $this->tenantId)->delete();
        DB::table('events')->where('tenant_id', $this->tenantId)->delete();
        DB::table('venues')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    User::query()->delete();
});

it('builds and publishes a GA event entirely over /v1 in one sequence', function () {
    $venueId = $this->postJson('/v1/venues', [
        'name' => 'Grand Arena',
        'address' => '123 Main St',
        'city' => 'Austin',
        'country' => 'US',
        'capacity' => 5000,
    ])
        ->assertCreated()
        ->assertConformsToOpenApi()
        ->json('id');

    $eventId = $this->postJson('/v1/events', [
        'name' => ['en' => 'Grand Gala'],
        'description' => ['en' => 'A gala event.'],
        'venue_id' => $venueId,
        'is_virtual' => false,
        'virtual_event_url' => null,
        'start_at' => '2026-08-01T18:00:00Z',
        'end_at' => '2026-08-01T21:00:00Z',
        'timezone' => 'America/Chicago',
    ])
        ->assertCreated()
        ->assertConformsToOpenApi()
        ->assertJsonPath('venue_id', $venueId)
        ->assertJsonPath('status', 'draft')
        ->json('id');

    $this->postJson("/v1/events/{$eventId}/ticket-types", [
        'name' => 'General Admission',
        'price' => ['amount' => 5000, 'currency' => 'USD'],
        'sales_start' => null,
        'sales_end' => null,
    ])
        ->assertCreated()
        ->assertConformsToOpenApi()
        ->assertJsonPath('event_id', $eventId)
        ->assertJsonPath('price', ['amount' => 5000, 'currency' => 'USD']);

    $this->postJson("/v1/events/{$eventId}/publish")
        ->assertOk()
        ->assertConformsToOpenApi()
        ->assertJsonPath('id', $eventId)
        ->assertJsonPath('status', 'published');
});

it('builds and publishes a virtual event entirely over /v1 in one sequence', function () {
    $eventId = $this->postJson('/v1/events', [
        'name' => ['en' => 'Virtual Summit'],
        'description' => ['en' => 'Online only.'],
        'venue_id' => null,
        'is_virtual' => true,
        'virtual_event_url' => 'https://example.test/stream',
        'start_at' => '2026-08-01T18:00:00Z',
        'end_at' => '2026-08-01T21:00:00Z',
        'timezone' => 'UTC',
    ])
        ->assertCreated()
        ->assertConformsToOpenApi()
        ->assertJsonPath('venue_id', null)
        ->assertJsonPath('is_virtual', true)
        ->assertJsonPath('status', 'draft')
        ->json('id');

    $this->postJson("/v1/events/{$eventId}/ticket-types", [
        'name' => 'Stream Access',
        'price' => ['amount' => 2500, 'currency' => 'USD'],
        'sales_start' => null,
        'sales_end' => null,
    ])
        ->assertCreated()
        ->assertConformsToOpenApi()
        ->assertJsonPath('event_id', $eventId)
        ->assertJsonPath('price', ['amount' => 2500, 'currency' => 'USD']);

    $this->postJson("/v1/events/{$eventId}/publish")
        ->assertOk()
        ->assertConformsToOpenApi()
        ->assertJsonPath('id', $eventId)
        ->assertJsonPath('status', 'published');
});
