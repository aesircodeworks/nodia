<?php

use App\EventCatalog\Actions\CreateVenue;
use App\EventCatalog\Data\CreateVenueData;
use App\EventCatalog\Data\VenueData;
use App\EventCatalog\Models\Venue;
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
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Venue::query()->where('tenant_id', $this->tenantId)->delete(),
    );

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );
});

it('creates a venue scoped to the acting tenant and returns its VenueData', function () {
    $data = CreateVenueData::from([
        'name' => 'Grand Arena',
        'address' => '123 Main St',
        'city' => 'Austin',
        'country' => 'US',
        'capacity' => 5000,
    ]);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateVenue::class)($data),
    );

    expect($result)->toBeInstanceOf(VenueData::class)
        ->and(Str::isUuid($result->id))->toBeTrue()
        ->and($result->tenantId)->toBe($this->tenantId)
        ->and($result->name)->toBe('Grand Arena')
        ->and($result->address)->toBe('123 Main St')
        ->and($result->city)->toBe('Austin')
        ->and($result->country)->toBe('US')
        ->and($result->capacity)->toBe(5000);

    $venue = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Venue::query()->find($result->id),
    );

    expect($venue)->not->toBeNull()
        ->and($venue->tenant_id)->toBe($this->tenantId);
});

it('serializes VenueData with snake_case keys and ISO 8601 UTC timestamps', function () {
    $data = CreateVenueData::from([
        'name' => 'Grand Arena',
        'address' => '123 Main St',
        'city' => 'Austin',
        'country' => 'US',
        'capacity' => 5000,
    ]);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateVenue::class)($data),
    );

    $wire = $result->toArray();

    expect(array_keys($wire))->toBe([
        'id', 'tenant_id', 'name', 'address', 'city', 'country', 'capacity', 'created_at', 'updated_at',
    ])
        ->and($wire['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($wire['updated_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});
