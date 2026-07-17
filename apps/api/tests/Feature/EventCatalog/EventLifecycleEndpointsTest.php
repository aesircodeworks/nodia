<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\Identity\Capability;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\TenantStaff;

/*
 * Stage-05a plan, task breakdown item 9 (TDD slice 4 completion): publish
 * and cancel over POST /v1/events/{event}/publish and
 * .../cancel, mirroring tests/Feature/EventCatalog/EventEndpointsTest.php's
 * own structure. Closes out
 * tests/Feature/EventCatalog/CatalogAuthorizationMatrixTest.php's own
 * retired probe rows for these two routes (this file's 401/403 cases below
 * carry that coverage forward through the real routes).
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $this->otherTenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->withHeaders([
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, [Capability::EventsView, Capability::EventsManage, Capability::EventsPublish]),
        'X-Tenant-Id' => $this->tenantId,
    ]);
});

afterEach(function (): void {
    foreach ([$this->tenantId, $this->otherTenantId] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
            DB::table('memberships')->where('tenant_id', $tenantId)->delete();
            DB::table('events')->where('tenant_id', $tenantId)->delete();
            DB::table('venues')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    User::query()->delete();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function makeLifecycleEvent(string $tenantId, array $attributes = []): Event
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Event::factory()->create(['tenant_id' => $tenantId, ...$attributes]),
    );
}

it('publishes a draft event', function () {
    $event = makeLifecycleEvent($this->tenantId, ['status' => EventStatus::Draft]);

    $this->postJson('/v1/events/'.$event->id.'/publish')
        ->assertOk()
        ->assertConformsToOpenApi()
        ->assertJsonPath('id', $event->id)
        ->assertJsonPath('status', 'published');
});

it('rejects publishing an already-published event with catalog.event_not_publishable', function () {
    $event = makeLifecycleEvent($this->tenantId, ['status' => EventStatus::Published]);

    $this->postJson('/v1/events/'.$event->id.'/publish')
        ->assertStatus(409)
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'catalog.event_not_publishable');
});

it('rejects publishing a canceled event with catalog.event_not_publishable', function () {
    $event = makeLifecycleEvent($this->tenantId, ['status' => EventStatus::Canceled]);

    $this->postJson('/v1/events/'.$event->id.'/publish')
        ->assertStatus(409)
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'catalog.event_not_publishable');
});

it('renders request.not_found publishing an unknown or cross-tenant event id', function () {
    $this->postJson('/v1/events/'.Str::uuid7().'/publish')
        ->assertNotFound()
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'request.not_found');

    $foreignEvent = makeLifecycleEvent($this->otherTenantId, ['status' => EventStatus::Draft]);

    $this->postJson('/v1/events/'.$foreignEvent->id.'/publish')
        ->assertNotFound()
        ->assertJsonPath('code', 'request.not_found');
});

it('cancels a draft event, recording the prior status', function () {
    $event = makeLifecycleEvent($this->tenantId, ['status' => EventStatus::Draft]);

    $this->postJson('/v1/events/'.$event->id.'/cancel')
        ->assertOk()
        ->assertConformsToOpenApi()
        ->assertJsonPath('id', $event->id)
        ->assertJsonPath('status', 'canceled');
});

it('cancels a published event', function () {
    $event = makeLifecycleEvent($this->tenantId, ['status' => EventStatus::Published]);

    $this->postJson('/v1/events/'.$event->id.'/cancel')
        ->assertOk()
        ->assertConformsToOpenApi()
        ->assertJsonPath('id', $event->id)
        ->assertJsonPath('status', 'canceled');
});

it('rejects canceling an already-canceled event with catalog.event_not_cancelable', function () {
    $event = makeLifecycleEvent($this->tenantId, ['status' => EventStatus::Canceled]);

    $this->postJson('/v1/events/'.$event->id.'/cancel')
        ->assertStatus(409)
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'catalog.event_not_cancelable');
});

it('renders request.not_found canceling an unknown or cross-tenant event id', function () {
    $this->postJson('/v1/events/'.Str::uuid7().'/cancel')
        ->assertNotFound()
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'request.not_found');

    $foreignEvent = makeLifecycleEvent($this->otherTenantId, ['status' => EventStatus::Draft]);

    $this->postJson('/v1/events/'.$foreignEvent->id.'/cancel')
        ->assertNotFound()
        ->assertJsonPath('code', 'request.not_found');
});

it('rejects a request with no bearer on publish and cancel', function (string $action) {
    $event = makeLifecycleEvent($this->tenantId, ['status' => EventStatus::Draft]);

    $this->withoutToken()
        ->postJson('/v1/events/'.$event->id.'/'.$action, [], ['X-Tenant-Id' => $this->tenantId])
        ->assertUnauthorized()
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'auth.unauthenticated');
})->with(['publish', 'cancel']);

it('rejects a bearer lacking events.publish on publish and cancel with missing_capability', function (string $action) {
    $event = makeLifecycleEvent($this->tenantId, ['status' => EventStatus::Draft]);
    $token = TenantStaff::token($this->tenantId, [Capability::EventsView, Capability::EventsManage]);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/v1/events/'.$event->id.'/'.$action)
        ->assertForbidden()
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'missing_capability');
})->with(['publish', 'cancel']);
