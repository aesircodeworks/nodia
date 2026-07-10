<?php

use App\EventCatalog\Actions\UpdateEvent;
use App\EventCatalog\Data\EventData;
use App\EventCatalog\Data\UpdateEventData;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\Venue;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->event = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create([
            'tenant_id' => $this->tenantId,
            'name' => ['en' => 'Before'],
            'timezone' => 'UTC',
        ]),
    );
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

it('updates the given fields and returns EventData', function () {
    $data = UpdateEventData::from(['timezone' => 'America/Chicago']);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateEvent::class)($this->event, $data),
    );

    expect($result)->toBeInstanceOf(EventData::class)
        ->and($result->timezone)->toBe('America/Chicago')
        ->and($result->name)->toBe(['en' => 'Before']);
});

it('leaves fields absent from the payload untouched', function () {
    $data = UpdateEventData::from(['timezone' => 'America/Chicago']);

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateEvent::class)($this->event, $data),
    );

    $fresh = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::query()->find($this->event->id),
    );

    expect($fresh->getTranslations('name'))->toBe(['en' => 'Before'])
        ->and($fresh->timezone)->toBe('America/Chicago');
});

it('updates the venue/virtual trio together', function () {
    $venue = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Venue::factory()->create(['tenant_id' => $this->tenantId]),
    );

    $data = UpdateEventData::from([
        'is_virtual' => false,
        'venue_id' => $venue->id,
        'virtual_event_url' => null,
    ]);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateEvent::class)($this->event, $data),
    );

    expect($result->isVirtual)->toBeFalse()
        ->and($result->venueId)->toBe($venue->id)
        ->and($result->virtualEventUrl)->toBeNull();
});

it('records an EventUpdated outbox row on every successful call', function () {
    $data = UpdateEventData::from(['timezone' => 'America/Chicago']);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateEvent::class)($this->event, $data),
    );

    $rows = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventUpdated')->where('aggregate_id', $result->id)->get(),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->payload)->toBe(['event_id' => $result->id]);
});
