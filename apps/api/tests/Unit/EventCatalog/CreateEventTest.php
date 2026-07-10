<?php

use App\EventCatalog\Actions\CreateEvent;
use App\EventCatalog\Data\CreateEventData;
use App\EventCatalog\Data\EventData;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\Venue;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        OutboxEvent::query()->where('tenant_id', $this->tenantId)->delete();
        Event::query()->where('tenant_id', $this->tenantId)->delete();
        Venue::query()->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );
});

it('creates an event scoped to the acting tenant and returns its EventData', function () {
    $venue = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Venue::factory()->create(['tenant_id' => $this->tenantId]),
    );

    $data = CreateEventData::from([
        'name' => ['en' => 'Grand Gala'],
        'description' => ['en' => 'A gala event.'],
        'venue_id' => $venue->id,
        'is_virtual' => false,
        'virtual_event_url' => null,
        'start_at' => '2026-08-01T18:00:00Z',
        'end_at' => '2026-08-01T21:00:00Z',
        'timezone' => 'America/Chicago',
    ]);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateEvent::class)($data),
    );

    expect($result)->toBeInstanceOf(EventData::class)
        ->and(Str::isUuid($result->id))->toBeTrue()
        ->and($result->tenantId)->toBe($this->tenantId)
        ->and($result->venueId)->toBe($venue->id)
        ->and($result->status)->toBe('draft')
        ->and($result->name)->toBe(['en' => 'Grand Gala'])
        ->and($result->description)->toBe(['en' => 'A gala event.'])
        ->and($result->isVirtual)->toBeFalse()
        ->and($result->virtualEventUrl)->toBeNull()
        ->and($result->asyncPaymentPolicy->slowMethodsEnabled)->toBeTrue()
        ->and($result->asyncPaymentPolicy->lowInventoryCutoff)->toBeNull();

    $event = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::query()->find($result->id),
    );

    expect($event)->not->toBeNull()
        ->and($event->tenant_id)->toBe($this->tenantId);
});

it('records an EventCreated outbox row in the producing transaction', function () {
    $data = CreateEventData::from([
        'name' => ['en' => 'Virtual Summit'],
        'description' => ['en' => 'Online only.'],
        'venue_id' => null,
        'is_virtual' => true,
        'virtual_event_url' => 'https://example.test/stream',
        'start_at' => '2026-08-01T18:00:00Z',
        'end_at' => '2026-08-01T21:00:00Z',
        'timezone' => 'UTC',
    ]);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateEvent::class)($data),
    );

    $rows = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventCreated')->where('aggregate_id', $result->id)->get(),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->payload)->toBe(['event_id' => $result->id]);
});
