<?php

use App\EventCatalog\Models\Seat;
use App\EventCatalog\Models\SeatMap;
use App\EventCatalog\Models\Venue;
use App\Identity\Capability;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;
use Tests\Support\TenantStaff;

/**
 * The PUT /v1/seat-maps/{seat_map} atomicity race of the stage-05b plan,
 * TDD sequencing Slice 4 (task breakdown item 4), driven through the real
 * HTTP kernel in forked workers so each contender runs the full
 * production path: tenancy.admin route group, the request transaction,
 * and the Action's own lockForUpdate() on the seat_maps row. Unlike
 * EventLifecycleContentionTest's publish/cancel races, PUT has no
 * invariant that makes one contender's request illegal: both payloads are
 * equally valid full replacements, so the lock's only job is to serialize
 * them into a strict order, never an interleaved merge of the two
 * documents. Both requests are therefore expected to succeed; the
 * assertion that matters is that the final committed document is exactly
 * one payload's document, never a mix of the two, and that neither
 * request ever surfaces a 500 (a residual (seat_map_id, section, row,
 * number) unique violation, were the lock ever bypassed, would map to 409
 * catalog.seat_map_conflict instead).
 */
beforeEach(function (): void {
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
            DB::table('seats')->where('tenant_id', $tenantId)->delete();
            DB::table('seat_maps')->where('tenant_id', $tenantId)->delete();
            DB::table('venues')->where('tenant_id', $tenantId)->delete();
            DB::table('memberships')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });

    User::query()->delete();
});

/**
 * @return array{0: string, 1: SeatMap, 2: string}
 */
function seatMapReplaceFixture(): array
{
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $bearer = TenantStaff::token($tenantId, Capability::SeatMapsManage);

    $seatMap = app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): SeatMap {
        $venue = Venue::factory()->create(['tenant_id' => $tenantId]);

        return SeatMap::factory()->create([
            'tenant_id' => $tenantId,
            'venue_id' => $venue->id,
            'name' => 'Original Map',
        ]);
    });

    return [$tenantId, $seatMap, $bearer];
}

/**
 * @param  array<string, mixed>  $payload
 * @return array{status: int, code: string|null}
 */
function handleSeatMapReplaceRequest(string $uri, string $bearer, string $tenantId, array $payload): array
{
    $request = Request::create($uri, 'PUT', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$bearer,
        'HTTP_X_TENANT_ID' => $tenantId,
    ], json_encode($payload, JSON_THROW_ON_ERROR));

    $response = app(Kernel::class)->handle($request);

    $body = json_decode((string) $response->getContent(), true);

    return [
        'status' => $response->getStatusCode(),
        'code' => is_array($body) ? ($body['code'] ?? null) : null,
    ];
}

it('resolves parallel PUTs of one seat map to exactly one fully-applied document, never an interleaved merge', function () {
    [$tenantId, $seatMap, $bearer] = seatMapReplaceFixture();

    $payloadA = [
        'name' => 'Racer A',
        'layout' => ['stage' => 'north'],
        'seats' => [
            ['section' => 'A', 'row' => '1', 'number' => '1', 'position_x' => null, 'position_y' => null],
            ['section' => 'A', 'row' => '1', 'number' => '2', 'position_x' => null, 'position_y' => null],
        ],
    ];

    $payloadB = [
        'name' => 'Racer B',
        'layout' => ['stage' => 'south'],
        'seats' => [
            ['section' => 'B', 'row' => '2', 'number' => '1', 'position_x' => null, 'position_y' => null],
            ['section' => 'B', 'row' => '2', 'number' => '2', 'position_x' => null, 'position_y' => null],
            ['section' => 'B', 'row' => '2', 'number' => '3', 'position_x' => null, 'position_y' => null],
        ],
    ];

    $results = ParallelRunner::runEach(
        fn (PDO $pdo): array => handleSeatMapReplaceRequest('/v1/seat-maps/'.$seatMap->id, $bearer, $tenantId, $payloadA),
        fn (PDO $pdo): array => handleSeatMapReplaceRequest('/v1/seat-maps/'.$seatMap->id, $bearer, $tenantId, $payloadB),
    );

    $statuses = collect($results)->pluck('status');

    // Never a 500: the lock either serializes both replaces to success, or,
    // in the unreachable-in-practice case the lock is ever bypassed, a
    // residual unique violation must still surface as 409, never opaque.
    expect($statuses->reject(fn (int $s): bool => in_array($s, [200, 409], true)))->toBeEmpty();

    foreach ($results as $result) {
        if ($result['status'] === 409) {
            expect($result['code'])->toBe('catalog.seat_map_conflict');
        }
    }

    $final = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => SeatMap::query()->with('seats')->whereKey($seatMap->id)->firstOrFail(),
    );

    $finalKeys = $final->seats->map(fn (Seat $seat): array => [$seat->section, $seat->row, $seat->number])->all();

    $matchesA = $final->name === 'Racer A'
        && $final->layout === ['stage' => 'north']
        && $finalKeys === [['A', '1', '1'], ['A', '1', '2']];

    $matchesB = $final->name === 'Racer B'
        && $final->layout === ['stage' => 'south']
        && $finalKeys === [['B', '2', '1'], ['B', '2', '2'], ['B', '2', '3']];

    // Exactly one payload's document landed in full: never a name from one
    // payload paired with seats from the other, and never a union or
    // partial mix of the two seat sets.
    expect($matchesA xor $matchesB)->toBeTrue();
});
