<?php

use App\EventCatalog\Actions\UpdateVenue;
use App\EventCatalog\Data\UpdateVenueData;
use App\EventCatalog\Data\VenueData;
use App\EventCatalog\Models\Venue;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->venue = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Venue::factory()->create([
            'tenant_id' => $this->tenantId,
            'name' => 'Before',
            'city' => 'Austin',
            'capacity' => 100,
        ]),
    );
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Venue::query()->where('tenant_id', $this->tenantId)->delete(),
    );

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );
});

it('updates the given fields and returns VenueData', function () {
    $data = UpdateVenueData::from(['name' => 'After', 'capacity' => 200]);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateVenue::class)($this->venue, $data),
    );

    expect($result)->toBeInstanceOf(VenueData::class)
        ->and($result->name)->toBe('After')
        ->and($result->capacity)->toBe(200);

    $fresh = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Venue::query()->find($this->venue->id),
    );

    expect($fresh->name)->toBe('After')
        ->and($fresh->capacity)->toBe(200)
        ->and($fresh->city)->toBe('Austin');
});

it('leaves fields absent from the payload untouched', function () {
    $data = UpdateVenueData::from(['city' => 'Dallas']);

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateVenue::class)($this->venue, $data),
    );

    $fresh = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Venue::query()->find($this->venue->id),
    );

    expect($fresh->city)->toBe('Dallas')
        ->and($fresh->name)->toBe('Before')
        ->and($fresh->capacity)->toBe(100);
});
