<?php

use App\EventCatalog\Data\AsyncPaymentPolicyData;
use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\Venue;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-05a plan, task breakdown item 4: model with translatable casts.
 * DB-backed (unlike EventStatusTest and EventVenueOrUrlInvariantTest)
 * because events.status's DEFAULT and the async_payment_policy jsonb
 * round-trip are both properties of the creating migration and the
 * DataEloquentCast, not pure PHP, mirroring CreateVenueTest's own
 * precedent for exercising a model through a real tenant transaction.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        function (): void {
            Event::query()->where('tenant_id', $this->tenantId)->delete();
            Venue::query()->where('tenant_id', $this->tenantId)->delete();
        },
    );

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );
});

it('defaults a newly created event to draft when status is not supplied', function () {
    $event = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create(['tenant_id' => $this->tenantId]),
    );

    expect($event->fresh()->status)->toBe(EventStatus::Draft);
});

it('round-trips async_payment_policy through the AsyncPaymentPolicyData cast, including its defaults', function () {
    $event = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create(['tenant_id' => $this->tenantId]),
    );

    $fresh = $event->fresh();

    expect($fresh->async_payment_policy)->toBeInstanceOf(AsyncPaymentPolicyData::class)
        ->and($fresh->async_payment_policy->slowMethodsEnabled)->toBeTrue()
        ->and($fresh->async_payment_policy->lowInventoryCutoff)->toBeNull();
});

it('preserves a non-default async_payment_policy through the cast', function () {
    $event = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create([
            'tenant_id' => $this->tenantId,
            'async_payment_policy' => new AsyncPaymentPolicyData(slowMethodsEnabled: false, lowInventoryCutoff: 10),
        ]),
    );

    $fresh = $event->fresh();

    expect($fresh->async_payment_policy->slowMethodsEnabled)->toBeFalse()
        ->and($fresh->async_payment_policy->lowInventoryCutoff)->toBe(10);
});

it('stores and retrieves locale-keyed name and description via HasTranslations', function () {
    $event = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create([
            'tenant_id' => $this->tenantId,
            'name' => ['en' => 'Summer Festival', 'fr' => 'Festival d\'Été'],
            'description' => ['en' => 'A summer music festival.', 'fr' => 'Un festival de musique d\'été.'],
        ]),
    );

    $fresh = $event->fresh();

    expect($fresh->getTranslation('name', 'en'))->toBe('Summer Festival')
        ->and($fresh->getTranslation('name', 'fr'))->toBe('Festival d\'Été')
        ->and($fresh->getTranslations('description'))->toBe([
            'en' => 'A summer music festival.',
            'fr' => 'Un festival de musique d\'été.',
        ]);
});

it('creates a virtual event with no venue by default', function () {
    $event = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create(['tenant_id' => $this->tenantId]),
    );

    expect($event->fresh())
        ->is_virtual->toBeTrue()
        ->venue_id->toBeNull()
        ->virtual_event_url->not->toBeNull();
});

it('creates a physical event pinned to a venue via the atVenue factory state', function () {
    $venue = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Venue::factory()->create(['tenant_id' => $this->tenantId]),
    );

    $event = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->atVenue($venue->id)->create(['tenant_id' => $this->tenantId]),
    );

    expect($event->fresh())
        ->is_virtual->toBeFalse()
        ->venue_id->toBe($venue->id)
        ->virtual_event_url->toBeNull();
});
