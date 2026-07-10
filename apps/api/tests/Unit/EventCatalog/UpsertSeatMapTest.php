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

/**
 * @param  array<string, mixed>  $attributes
 */
function makeUnitSeatMapRow(string $tenantId, string $venueId, array $attributes = []): SeatMap
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => SeatMap::factory()->create(['tenant_id' => $tenantId, 'venue_id' => $venueId, ...$attributes]),
    );
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeUnitSeatRow(string $tenantId, string $seatMapId, array $attributes = []): Seat
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Seat::factory()->create(['tenant_id' => $tenantId, 'seat_map_id' => $seatMapId, ...$attributes]),
    );
}

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

/*
 * Stage-05b plan, task breakdown item 4 (TDD slice 4): UpsertSeatMap::
 * replace() completes the Action with seat identity preservation, mirroring
 * this file's own create()-path structure above.
 */
it('preserves a seat\'s id across a coordinate-only change matched by its unchanged natural key', function () {
    $seatMap = makeUnitSeatMapRow($this->tenantId, $this->venue->id, ['name' => 'Replace Target']);
    $seat = makeUnitSeatRow($this->tenantId, $seatMap->id, [
        'section' => 'A', 'row' => '1', 'number' => '1', 'position_x' => 0, 'position_y' => 0,
    ]);

    $data = UpsertSeatMapData::from(upsertSeatMapPayload([
        'name' => 'Replace Target',
        'seats' => [
            ['section' => 'A', 'row' => '1', 'number' => '1', 'position_x' => 5, 'position_y' => 5],
        ],
    ]));

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpsertSeatMap::class)->replace($seatMap, $data),
    );

    expect($result->seats)->toHaveCount(1);

    $replacedSeat = $result->seats->toCollection()->first();

    expect($replacedSeat->id)->toBe($seat->id)
        ->and($replacedSeat->positionX)->toBe(5)
        ->and($replacedSeat->positionY)->toBe(5);
});

it('deletes a seat omitted from the replace payload', function () {
    $seatMap = makeUnitSeatMapRow($this->tenantId, $this->venue->id, ['name' => 'Omit Target']);
    $kept = makeUnitSeatRow($this->tenantId, $seatMap->id, ['section' => 'A', 'row' => '1', 'number' => '1']);
    $omitted = makeUnitSeatRow($this->tenantId, $seatMap->id, ['section' => 'A', 'row' => '1', 'number' => '2']);

    $data = UpsertSeatMapData::from(upsertSeatMapPayload([
        'name' => 'Omit Target',
        'seats' => [
            ['section' => 'A', 'row' => '1', 'number' => '1', 'position_x' => null, 'position_y' => null],
        ],
    ]));

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpsertSeatMap::class)->replace($seatMap, $data),
    );

    $remainingIds = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Seat::query()->where('seat_map_id', $seatMap->id)->pluck('id')->all(),
    );

    expect($remainingIds)->toBe([$kept->id])
        ->and($remainingIds)->not->toContain($omitted->id);
});

it('inserts a seat added to the replace payload with a new id', function () {
    $seatMap = makeUnitSeatMapRow($this->tenantId, $this->venue->id, ['name' => 'Add Target']);
    makeUnitSeatRow($this->tenantId, $seatMap->id, ['section' => 'A', 'row' => '1', 'number' => '1']);

    $data = UpsertSeatMapData::from(upsertSeatMapPayload([
        'name' => 'Add Target',
        'seats' => [
            ['section' => 'A', 'row' => '1', 'number' => '1', 'position_x' => null, 'position_y' => null],
            ['section' => 'A', 'row' => '1', 'number' => '2', 'position_x' => null, 'position_y' => null],
        ],
    ]));

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpsertSeatMap::class)->replace($seatMap, $data),
    );

    expect($result->seats)->toHaveCount(2);

    $added = $result->seats->toCollection()->firstWhere('number', '2');

    expect(Str::isUuid($added->id))->toBeTrue();
});

it('replaces the name and layout of an existing map', function () {
    $seatMap = makeUnitSeatMapRow($this->tenantId, $this->venue->id, ['name' => 'Old Name', 'layout' => ['stage' => 'south']]);

    $data = UpsertSeatMapData::from(upsertSeatMapPayload([
        'name' => 'New Name',
        'layout' => ['stage' => 'north'],
        'seats' => [],
    ]));

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpsertSeatMap::class)->replace($seatMap, $data),
    );

    expect($result->name)->toBe('New Name')
        ->and($result->layout)->toBe(['stage' => 'north']);
});

it('rejects in-payload duplicate natural keys before touching the database on replace', function () {
    $seatMap = makeUnitSeatMapRow($this->tenantId, $this->venue->id, ['name' => 'Dup Target']);
    makeUnitSeatRow($this->tenantId, $seatMap->id, ['section' => 'A', 'row' => '1', 'number' => '1']);

    $data = UpsertSeatMapData::from(upsertSeatMapPayload([
        'name' => 'Dup Target',
        'seats' => [
            ['section' => 'A', 'row' => '1', 'number' => '2', 'position_x' => null, 'position_y' => null],
            ['section' => 'A', 'row' => '1', 'number' => '2', 'position_x' => null, 'position_y' => null],
        ],
    ]));

    expect(fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpsertSeatMap::class)->replace($seatMap, $data),
    ))->toThrow(SeatMapDuplicateSeatsException::class);

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Seat::query()->where('seat_map_id', $seatMap->id)->count(),
    );

    expect($count)->toBe(1);
});

it('is one transaction: a failing rename to a taken name leaves the original document intact, seats included', function () {
    makeUnitSeatMapRow($this->tenantId, $this->venue->id, ['name' => 'Already Taken']);

    $seatMap = makeUnitSeatMapRow($this->tenantId, $this->venue->id, ['name' => 'Rename Me', 'layout' => ['stage' => 'south']]);
    $originalSeat = makeUnitSeatRow($this->tenantId, $seatMap->id, [
        'section' => 'A', 'row' => '1', 'number' => '1', 'position_x' => 0, 'position_y' => 0,
    ]);

    $data = UpsertSeatMapData::from(upsertSeatMapPayload([
        // renaming both the seat_maps row (to a name taken by the other
        // map above) and the seat set in the same request: if the whole
        // replacement were not one transaction, the seat mutations below
        // could commit before the name uniqueness check fails.
        'name' => 'Already Taken',
        'layout' => ['stage' => 'north'],
        'seats' => [
            ['section' => 'A', 'row' => '1', 'number' => '1', 'position_x' => 9, 'position_y' => 9],
            ['section' => 'B', 'row' => '2', 'number' => '1', 'position_x' => null, 'position_y' => null],
        ],
    ]));

    expect(fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpsertSeatMap::class)->replace($seatMap, $data),
    ))->toThrow(SeatMapNameTakenException::class);

    $persisted = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => SeatMap::query()->with('seats')->find($seatMap->id),
    );

    expect($persisted->name)->toBe('Rename Me')
        ->and($persisted->layout)->toBe(['stage' => 'south'])
        ->and($persisted->seats)->toHaveCount(1);

    $survivingSeat = $persisted->seats->first();

    expect($survivingSeat->id)->toBe($originalSeat->id)
        ->and($survivingSeat->position_x)->toBe(0)
        ->and($survivingSeat->position_y)->toBe(0);
});
