<?php

declare(strict_types=1);

use App\EventCatalog\Jobs\RefreshSearchIndex;
use App\Identity\Capability;
use App\Models\User;
use App\Support\Outbox\Enums\OutboxDeliveryStatus;
use App\Support\Outbox\Jobs\ProcessOutboxDelivery;
use App\Support\Outbox\Models\OutboxDelivery;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OrderedConsumption;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\TenantStaff;

/*
 * Stage-05c plan, task breakdown item 7, TDD sequencing Slice 5,
 * Feature/consumer tests (first, failing): the mandatory duplicate-
 * delivery test, per-locale content with fallback on EventPublished,
 * refresh-versus-delete on EventUpdated, full deletion on EventCanceled,
 * no-op on EventCreated, and out-of-order convergence on current event
 * state. Driven through the real POST/PATCH admin endpoints (stage-05a)
 * so RefreshSearchIndex is exercised the way production dispatches it:
 * registered in App\EventCatalog\EventCatalogServiceProvider, delivered
 * by the shared App\Support\Outbox\Jobs\ProcessOutboxDelivery under the
 * default sync test queue connection, mirroring
 * tests/Feature/EventCatalog/EventLifecycleOutboxTest.php's own HTTP-first
 * structure.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create([
            'default_locale' => 'en',
            'supported_locales' => ['en', 'fr'],
        ])->id,
    );

    $this->withHeaders([
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, [Capability::EventsView, Capability::EventsManage, Capability::EventsPublish]),
        'X-Tenant-Id' => $this->tenantId,
    ]);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('event_search_documents')->where('tenant_id', $this->tenantId)->delete();
        DB::table('outbox_deliveries')->where('tenant_id', $this->tenantId)->delete();
        DB::table('outbox_events')->where('tenant_id', $this->tenantId)->delete();
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
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function refreshIndexEventPayload(array $overrides = []): array
{
    return [
        'name' => ['en' => 'Jazz Festival'],
        'description' => ['en' => 'Live music all weekend'],
        'venue_id' => null,
        'is_virtual' => true,
        'virtual_event_url' => 'https://example.test/stream',
        'start_at' => '2026-08-01T18:00:00Z',
        'end_at' => '2026-08-01T21:00:00Z',
        'timezone' => 'UTC',
        ...$overrides,
    ];
}

/**
 * @return list<object>
 */
function refreshIndexDocuments(string $tenantId, string $eventId): array
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => DB::table('event_search_documents')->where('event_id', $eventId)->orderBy('locale')->get()->all(),
    );
}

function refreshIndexOutboxEventId(string $tenantId, string $type, string $eventId): string
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => OutboxEvent::query()
            ->where('type', $type)
            ->where('aggregate_id', $eventId)
            ->orderByDesc('sequence')
            ->firstOrFail()
            ->id,
    );
}

function runRefreshSearchIndexDelivery(string $outboxEventId): void
{
    (new ProcessOutboxDelivery($outboxEventId, RefreshSearchIndex::NAME))->handle(
        app(TenantTransaction::class),
        app(SubscriberRegistry::class),
        app(OrderedConsumption::class),
    );
}

it('registers the refresh_search_index subscriber for all four catalog event types', function (): void {
    $registry = app(SubscriberRegistry::class);

    foreach (['EventCreated', 'EventUpdated', 'EventPublished', 'EventCanceled'] as $type) {
        expect($registry->namesFor($type))->toContain(RefreshSearchIndex::NAME);
    }
});

it('does not index a draft event created through the API', function (): void {
    $response = $this->postJson('/v1/events', refreshIndexEventPayload());
    $response->assertCreated();

    expect(refreshIndexDocuments($this->tenantId, $response->json('id')))->toBeEmpty();
});

it('creates one document per tenant-supported locale with fallback-resolved content when a draft is published', function (): void {
    $create = $this->postJson('/v1/events', refreshIndexEventPayload());
    $create->assertCreated();
    $eventId = $create->json('id');

    $this->postJson("/v1/events/{$eventId}/publish")->assertOk();

    $documents = collect(refreshIndexDocuments($this->tenantId, $eventId))->keyBy('locale');

    expect($documents)->toHaveCount(2)
        ->and($documents->keys()->sort()->values()->all())->toBe(['en', 'fr'])
        ->and($documents['en']->name)->toBe('Jazz Festival')
        ->and($documents['en']->description)->toBe('Live music all weekend')
        ->and($documents['fr']->name)->toBe('Jazz Festival')
        ->and($documents['fr']->description)->toBe('Live music all weekend')
        ->and($documents['en']->tenant_id)->toBe($this->tenantId)
        ->and($documents['en']->search_vector)->not->toBeNull();
});

it('refreshes documents when EventUpdated fires on a currently published event', function (): void {
    $create = $this->postJson('/v1/events', refreshIndexEventPayload());
    $eventId = $create->json('id');
    $this->postJson("/v1/events/{$eventId}/publish")->assertOk();

    $this->patchJson("/v1/events/{$eventId}", ['name' => ['en' => 'Jazz Festival Reloaded']])->assertOk();

    $documents = collect(refreshIndexDocuments($this->tenantId, $eventId))->keyBy('locale');

    expect($documents)->toHaveCount(2)
        ->and($documents['en']->name)->toBe('Jazz Festival Reloaded')
        ->and($documents['fr']->name)->toBe('Jazz Festival Reloaded');
});

it('deletes stale documents when EventUpdated fires on an event that is not published', function (): void {
    $create = $this->postJson('/v1/events', refreshIndexEventPayload());
    $eventId = $create->json('id');

    // Plant a stray row directly: a draft never legitimately has search
    // documents, but the projector must clean up any it finds rather than
    // assuming its own prior effects are the only source of rows (stage-05c
    // plan, Domain events: "EventUpdated ... if not published, delete any
    // documents").
    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($eventId): void {
        DB::table('event_search_documents')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenantId,
            'event_id' => $eventId,
            'locale' => 'en',
            'name' => 'Stale',
            'description' => null,
            'search_vector' => DB::raw("to_tsvector('simple', 'stale')"),
            'event_starts_at' => now()->addWeek(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->patchJson("/v1/events/{$eventId}", ['timezone' => 'America/Chicago'])->assertOk();

    expect(refreshIndexDocuments($this->tenantId, $eventId))->toBeEmpty();
});

it('deletes all documents when EventCanceled fires', function (): void {
    $create = $this->postJson('/v1/events', refreshIndexEventPayload());
    $eventId = $create->json('id');
    $this->postJson("/v1/events/{$eventId}/publish")->assertOk();

    expect(refreshIndexDocuments($this->tenantId, $eventId))->not->toBeEmpty();

    $this->postJson("/v1/events/{$eventId}/cancel")->assertOk();

    expect(refreshIndexDocuments($this->tenantId, $eventId))->toBeEmpty();
});

it('runs the search projection effect exactly once when the same EventPublished delivery is processed twice', function (): void {
    Queue::fake();

    $create = $this->postJson('/v1/events', refreshIndexEventPayload());
    $eventId = $create->json('id');
    $this->postJson("/v1/events/{$eventId}/publish")->assertOk();

    $outboxEventId = refreshIndexOutboxEventId($this->tenantId, 'EventPublished', $eventId);

    processOutboxDeliveryTwice($outboxEventId, RefreshSearchIndex::NAME);

    expect(refreshIndexDocuments($this->tenantId, $eventId))->toHaveCount(2);

    $delivery = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxDelivery::query()
            ->where('outbox_event_id', $outboxEventId)
            ->where('subscriber', RefreshSearchIndex::NAME)
            ->firstOrFail(),
    );

    expect($delivery->status)->toBe(OutboxDeliveryStatus::Processed);
});

it('converges on the canceled state when EventUpdated is delivered after a later EventCanceled', function (): void {
    Queue::fake();

    $create = $this->postJson('/v1/events', refreshIndexEventPayload());
    $eventId = $create->json('id');
    $this->postJson("/v1/events/{$eventId}/publish")->assertOk();

    runRefreshSearchIndexDelivery(refreshIndexOutboxEventId($this->tenantId, 'EventPublished', $eventId));

    expect(refreshIndexDocuments($this->tenantId, $eventId))->toHaveCount(2);

    $this->patchJson("/v1/events/{$eventId}", ['timezone' => 'America/Chicago'])->assertOk();
    $this->postJson("/v1/events/{$eventId}/cancel")->assertOk();

    $updatedOutboxId = refreshIndexOutboxEventId($this->tenantId, 'EventUpdated', $eventId);
    $canceledOutboxId = refreshIndexOutboxEventId($this->tenantId, 'EventCanceled', $eventId);

    // Deliver the newer EventCanceled first, then the older EventUpdated
    // second: the projector re-reads the event's current row rather than
    // applying either payload as a delta, so the final state is driven by
    // that row (canceled), not by delivery order (stage-05c plan, Domain
    // events: "no per-aggregate ordered consumption").
    runRefreshSearchIndexDelivery($canceledOutboxId);
    runRefreshSearchIndexDelivery($updatedOutboxId);

    expect(refreshIndexDocuments($this->tenantId, $eventId))->toBeEmpty();
});
