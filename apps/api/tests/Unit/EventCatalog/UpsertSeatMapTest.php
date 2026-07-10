<?php

use App\EventCatalog\Actions\UpsertSeatMap;
use App\EventCatalog\Data\SeatMapData;
use App\EventCatalog\Data\UpsertSeatMapData;
use App\EventCatalog\Exceptions\SeatMapDuplicateSeatsException;
use App\EventCatalog\Exceptions\SeatMapNameTakenException;
use App\EventCatalog\Models\Seat;
use App\EventCatalog\Models\SeatMap;
use App\EventCatalog\Models\Venue;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-05b plan, task breakdown item 2 (TDD slice 2): UpsertSeatMap
 * Action unit coverage, create path only, mirroring
 * tests/Unit/EventCatalog/CreateVenueTest.php's own structure.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->venue = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Venue::factory()->create(['tenant_id' => $this->tenantId]),
    );
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        Seat::query()->where('tenant_id', $this->tenantId)->delete();
        SeatMap::query()->where('tenant_id', $this->tenantId)->delete();
        Venue::query()->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );
});

/**
 * @return array<string, mixed>
 */
function upsertSeatMapPayload(array $overrides = []): array
{
    return [
        'name' => 'Lower Bowl',
        'layout' => ['stage' => 'north'],
        'seats' => [
            ['section' => 'B', 'row' => '2', 'number' => '1', 'position_x' => null, 'position_y' => null],
            ['section' => 'A', 'row' => '1', 'number' => '2', 'position_x' => 2, 'position_y' => 1],
            ['section' => 'A', 'row' => '1', 'number' => '1', 'position_x' => 1, 'position_y' => 1],
        ],
        ...$overrides,
    ];
}

it('creates a seat map with its seats scoped to the venue and tenant, returning ordered SeatMapData', function () {
    $data = UpsertSeatMapData::from(upsertSeatMapPayload());

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpsertSeatMap::class)->create($this->venue, $data),
    );

    expect($result)->toBeInstanceOf(SeatMapData::class)
        ->and(Str::isUuid($result->id))->toBeTrue()
        ->and($result->tenantId)->toBe($this->tenantId)
        ->and($result->venueId)->toBe($this->venue->id)
        ->and($result->name)->toBe('Lower Bowl')
        ->and($result->layout)->toBe(['stage' => 'north'])
        ->and($result->seats)->toHaveCount(3);

    expect(array_map(
        fn ($seat) => [$seat->section, $seat->row, $seat->number],
        $result->seats->toCollection()->all(),
    ))->toBe([['A', '1', '1'], ['A', '1', '2'], ['B', '2', '1']]);

    $persisted = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => SeatMap::query()->with('seats')->find($result->id),
    );

    expect($persisted)->not->toBeNull()
        ->and($persisted->tenant_id)->toBe($this->tenantId)
        ->and($persisted->seats)->toHaveCount(3);

    foreach ($persisted->seats as $seat) {
        expect($seat->tenant_id)->toBe($this->tenantId)
            ->and($seat->seat_map_id)->toBe($persisted->id);
    }
});

it('rejects in-payload duplicate natural keys before touching the database', function () {
    $data = UpsertSeatMapData::from(upsertSeatMapPayload([
        'seats' => [
            ['section' => 'A', 'row' => '1', 'number' => '1', 'position_x' => null, 'position_y' => null],
            ['section' => 'B', 'row' => '1', 'number' => '1', 'position_x' => null, 'position_y' => null],
            ['section' => 'A', 'row' => '1', 'number' => '1', 'position_x' => null, 'position_y' => null],
        ],
    ]));

    expect(fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpsertSeatMap::class)->create($this->venue, $data),
    ))->toThrow(SeatMapDuplicateSeatsException::class);

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => SeatMap::query()->where('tenant_id', $this->tenantId)->count(),
    );

    expect($count)->toBe(0);
});

it('carries the offending positions on the duplicate-seats exception', function () {
    $data = UpsertSeatMapData::from(upsertSeatMapPayload([
        'seats' => [
            ['section' => 'A', 'row' => '1', 'number' => '1', 'position_x' => null, 'position_y' => null],
            ['section' => 'A', 'row' => '1', 'number' => '1', 'position_x' => null, 'position_y' => null],
            ['section' => 'B', 'row' => '1', 'number' => '1', 'position_x' => null, 'position_y' => null],
        ],
    ]));

    try {
        app(TenantTransaction::class)->asTenant(
            $this->tenantId,
            fn () => app(UpsertSeatMap::class)->create($this->venue, $data),
        );

        test()->fail('Expected SeatMapDuplicateSeatsException to be thrown.');
    } catch (SeatMapDuplicateSeatsException $e) {
        expect(array_keys($e->errors()))->toBe(['seats.0', 'seats.1']);
    }
});

it('rejects a duplicate name on the same venue with SeatMapNameTakenException', function () {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpsertSeatMap::class)->create($this->venue, UpsertSeatMapData::from(upsertSeatMapPayload(['name' => 'Duplicate']))),
    );

    expect(fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpsertSeatMap::class)->create($this->venue, UpsertSeatMapData::from(upsertSeatMapPayload(['name' => 'Duplicate', 'seats' => []]))),
    ))->toThrow(SeatMapNameTakenException::class);
});

it('persists an empty seats array without error', function () {
    $data = UpsertSeatMapData::from(upsertSeatMapPayload(['seats' => []]));

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpsertSeatMap::class)->create($this->venue, $data),
    );

    expect($result->seats)->toHaveCount(0);
});

it('persists seats via chunked bulk inserts inside one transaction: a failing name leaves no seats behind', function () {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpsertSeatMap::class)->create($this->venue, UpsertSeatMapData::from(upsertSeatMapPayload(['name' => 'Already Here', 'seats' => []]))),
    );

    $conflicting = UpsertSeatMapData::from(upsertSeatMapPayload(['name' => 'Already Here']));

    expect(fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpsertSeatMap::class)->create($this->venue, $conflicting),
    ))->toThrow(SeatMapNameTakenException::class);

    $seatCount = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Seat::query()->where('tenant_id', $this->tenantId)->count(),
    );

    expect($seatCount)->toBe(0);
});

it('generates a fresh UUIDv7 id for every seat, even across repeated identical-shaped payloads', function () {
    $first = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpsertSeatMap::class)->create($this->venue, UpsertSeatMapData::from(upsertSeatMapPayload(['name' => 'Map One']))),
    );

    $second = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpsertSeatMap::class)->create($this->venue, UpsertSeatMapData::from(upsertSeatMapPayload(['name' => 'Map Two']))),
    );

    $firstIds = $first->seats->toCollection()->pluck('id')->all();
    $secondIds = $second->seats->toCollection()->pluck('id')->all();

    foreach ([...$firstIds, ...$secondIds] as $id) {
        expect(Str::isUuid($id))->toBeTrue();
    }

    expect(array_intersect($firstIds, $secondIds))->toBe([]);
});
