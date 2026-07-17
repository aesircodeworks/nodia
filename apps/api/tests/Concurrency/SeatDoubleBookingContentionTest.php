<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\Seat;
use App\EventCatalog\Models\SeatMap;
use App\EventCatalog\Models\TicketType;
use App\EventCatalog\Models\Venue;
use App\Inventory\Enums\EventSeatStatus;
use App\Inventory\Models\EventSeat;
use App\Inventory\Models\TicketTypeInventory;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;

/**
 * The seat double-booking simulation (stage-06 plan, TDD sequencing
 * Slice 0 and Slice 6: "N parallel processes race for the same seat and
 * for overlapping seat sets; assert at most one active hold per seat and
 * full rollback of losers (no partial seat sets)"), driven through the
 * real HTTP kernel in forked workers so each contender runs the full
 * production path: the request transaction, the per-item held-increment
 * counter guard, and the per-seat conditional UPDATE that decides the
 * winner (App\Inventory\Actions\CreateHold::claimSeats).
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
            DB::table('hold_items')->where('tenant_id', $tenantId)->delete();
            DB::table('event_seats')->where('tenant_id', $tenantId)->delete();
            DB::table('holds')->where('tenant_id', $tenantId)->delete();
            DB::table('ticket_type_inventory')->where('tenant_id', $tenantId)->delete();
            DB::table('ticket_types')->where('tenant_id', $tenantId)->delete();
            DB::table('events')->where('tenant_id', $tenantId)->delete();
            DB::table('seats')->where('tenant_id', $tenantId)->delete();
            DB::table('seat_maps')->where('tenant_id', $tenantId)->delete();
            DB::table('venues')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

/**
 * A seated, zoned event with `$seatCount` materialized, available,
 * zoned seats.
 *
 * @return array{host: string, tenantId: string, eventId: string, ticketTypeId: string, seatIds: list<string>}
 */
function seatContentionFixture(int $seatCount): array
{
    ['tenant' => $tenant, 'domain' => $domain] = app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create();
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        return ['tenant' => $tenant, 'domain' => $domain];
    });

    return app(TenantTransaction::class)->asTenant($tenant->id, function () use ($tenant, $domain, $seatCount): array {
        $venue = Venue::factory()->create(['tenant_id' => $tenant->id]);
        $seatMap = SeatMap::factory()->create(['tenant_id' => $tenant->id, 'venue_id' => $venue->id]);
        $event = Event::factory()->atVenue($venue->id)->create(['tenant_id' => $tenant->id, 'status' => EventStatus::Published, 'seat_map_id' => $seatMap->id]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'requires_seat' => true]);
        TicketTypeInventory::factory()->create([
            'tenant_id' => $tenant->id,
            'ticket_type_id' => $ticketType->id,
            'quantity' => $seatCount,
            'held' => 0,
            'sold' => 0,
        ]);

        $seatIds = [];

        for ($i = 0; $i < $seatCount; $i++) {
            $templateSeat = Seat::factory()->create(['tenant_id' => $tenant->id, 'seat_map_id' => $seatMap->id, 'section' => 'A', 'row' => '1', 'number' => (string) ($i + 1)]);

            $seatIds[] = EventSeat::factory()->create([
                'tenant_id' => $tenant->id,
                'event_id' => $event->id,
                'seat_id' => $templateSeat->id,
                'ticket_type_id' => $ticketType->id,
                'status' => EventSeatStatus::Available,
            ])->id;
        }

        return [
            'host' => $domain->domain,
            'tenantId' => $tenant->id,
            'eventId' => $event->id,
            'ticketTypeId' => $ticketType->id,
            'seatIds' => $seatIds,
        ];
    });
}

/**
 * @param  list<string>  $seatIds
 * @return array{status: int, code: string|null}
 */
function handleSeatHoldRequest(string $host, string $eventId, string $ticketTypeId, array $seatIds): array
{
    $payload = [
        'event_id' => $eventId,
        'items' => [
            ['ticket_type_id' => $ticketTypeId, 'quantity' => count($seatIds)],
        ],
        'seat_ids' => $seatIds,
    ];

    $request = Request::create('http://'.$host.'/v1/storefront/holds', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], json_encode($payload, JSON_THROW_ON_ERROR));

    $response = app(Kernel::class)->handle($request);

    $body = json_decode((string) $response->getContent(), true);

    return [
        'status' => $response->getStatusCode(),
        'code' => is_array($body) ? ($body['code'] ?? null) : null,
    ];
}

it('lets exactly one worker claim a single contested seat, rolling every loser fully back', function (): void {
    ['host' => $host, 'tenantId' => $tenantId, 'eventId' => $eventId, 'ticketTypeId' => $ticketTypeId, 'seatIds' => $seatIds] = seatContentionFixture(1);

    $results = ParallelRunner::run(
        8,
        fn (PDO $pdo): array => handleSeatHoldRequest($host, $eventId, $ticketTypeId, $seatIds),
    );

    $statuses = collect($results)->pluck('status');

    expect($statuses->filter(fn (int $s): bool => $s === 201))->toHaveCount(1)
        ->and($statuses->reject(fn (int $s): bool => in_array($s, [201, 409], true)))->toBeEmpty();

    foreach ($results as $result) {
        if ($result['status'] === 409) {
            expect($result['code'])->toBeIn(['seat_unavailable', 'insufficient_inventory']);
        }
    }

    $seat = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => EventSeat::query()->whereKey($seatIds[0])->firstOrFail(),
    );
    $counter = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId)->firstOrFail(),
    );

    expect($seat->status)->toBe(EventSeatStatus::Held)
        ->and($counter->held)->toBe(1)
        ->and($counter->sold)->toBe(0);
});

it('resolves overlapping seat-set requests with no partial claims and no counter drift', function (): void {
    ['host' => $host, 'tenantId' => $tenantId, 'eventId' => $eventId, 'ticketTypeId' => $ticketTypeId, 'seatIds' => $seatIds] = seatContentionFixture(4);

    // Four workers each request an overlapping pair from a 4-seat pool
    // (seats 0-1, 1-2, 2-3, 3-0): every request that wins must claim both
    // of its own seats, and every seat can belong to at most one winner.
    $pairs = [
        [$seatIds[0], $seatIds[1]],
        [$seatIds[1], $seatIds[2]],
        [$seatIds[2], $seatIds[3]],
        [$seatIds[3], $seatIds[0]],
    ];

    $results = ParallelRunner::runEach(
        ...array_map(
            fn (array $pair): callable => fn (PDO $pdo): array => handleSeatHoldRequest($host, $eventId, $ticketTypeId, $pair),
            $pairs,
        ),
    );

    $statuses = collect($results)->pluck('status');

    expect($statuses->reject(fn (int $s): bool => in_array($s, [201, 409], true)))->toBeEmpty();

    foreach ($results as $result) {
        if ($result['status'] === 409) {
            expect($result['code'])->toBeIn(['seat_unavailable', 'insufficient_inventory']);
        }
    }

    $seats = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => EventSeat::query()->whereIn('id', $seatIds)->get(),
    );

    $heldCount = $seats->filter(fn (EventSeat $seat): bool => $seat->status === EventSeatStatus::Held)->count();
    $successCount = $statuses->filter(fn (int $s): bool => $s === 201)->count();

    $counter = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId)->firstOrFail(),
    );

    // Every seat is held by at most one hold (the unique event_id/seat_id
    // constraint plus the conditional claim make double-booking
    // structurally impossible); every winner claimed exactly its own
    // pair, so held seats are exactly 2 * successCount, and the counter
    // never drifts from what the seat table itself shows.
    expect($heldCount)->toBe(2 * $successCount)
        ->and($counter->held)->toBe($heldCount)
        ->and($seats->filter(fn (EventSeat $seat): bool => $seat->status === EventSeatStatus::Available)->count())->toBe(4 - $heldCount);
});
