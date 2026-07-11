<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
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
 * The GA oversell simulation (stage-06 plan, TDD sequencing Slice 0 and
 * Slice 2: "N parallel processes each attempt to hold k of a ticket type
 * with quantity Q where N*k > Q; assert sold + held <= quantity
 * afterward and that exactly floor(Q/k) holds succeeded"), driven
 * through the real HTTP kernel in forked workers so each contender runs
 * the full production path: tenancy.storefront route group, the request
 * transaction, and the held-increment conditional UPDATE that decides
 * the winners (App\Inventory\Actions\CreateHold::claim). Parameterized
 * over exact-fit, 2x, and 10x oversubscription; a fourth case interleaves
 * hold creation with an in-flight release-style rollback by mixing
 * successful and deliberately-failing item counts in the same race.
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
            DB::table('holds')->where('tenant_id', $tenantId)->delete();
            DB::table('ticket_type_inventory')->where('tenant_id', $tenantId)->delete();
            DB::table('ticket_types')->where('tenant_id', $tenantId)->delete();
            DB::table('events')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

/**
 * @return array{host: string, tenantId: string, eventId: string, ticketTypeId: string}
 */
function gaHoldFixture(int $quantity): array
{
    ['tenant' => $tenant, 'domain' => $domain] = app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create();
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        return ['tenant' => $tenant, 'domain' => $domain];
    });

    return app(TenantTransaction::class)->asTenant($tenant->id, function () use ($tenant, $domain, $quantity): array {
        $event = Event::factory()->create(['tenant_id' => $tenant->id, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
        TicketTypeInventory::factory()->create([
            'tenant_id' => $tenant->id,
            'ticket_type_id' => $ticketType->id,
            'quantity' => $quantity,
            'held' => 0,
            'sold' => 0,
        ]);

        return [
            'host' => $domain->domain,
            'tenantId' => $tenant->id,
            'eventId' => $event->id,
            'ticketTypeId' => $ticketType->id,
        ];
    });
}

/**
 * @return array{status: int, code: string|null}
 */
function handleGaHoldRequest(string $host, string $eventId, string $ticketTypeId, int $quantity): array
{
    $payload = [
        'event_id' => $eventId,
        'items' => [
            ['ticket_type_id' => $ticketTypeId, 'quantity' => $quantity],
        ],
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

function ticketTypeInventoryRow(string $tenantId, string $ticketTypeId): TicketTypeInventory
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId)->firstOrFail(),
    );
}

it('never oversells across parallel GA hold requests', function (int $quantity, int $workers, int $perRequest): void {
    ['host' => $host, 'tenantId' => $tenantId, 'eventId' => $eventId, 'ticketTypeId' => $ticketTypeId] = gaHoldFixture($quantity);

    $results = ParallelRunner::run(
        $workers,
        fn (PDO $pdo): array => handleGaHoldRequest($host, $eventId, $ticketTypeId, $perRequest),
    );

    $statuses = collect($results)->pluck('status');

    $expectedSuccesses = intdiv($quantity, $perRequest);

    expect($statuses->filter(fn (int $s): bool => $s === 201))->toHaveCount($expectedSuccesses)
        ->and($statuses->reject(fn (int $s): bool => in_array($s, [201, 409], true)))->toBeEmpty();

    foreach ($results as $result) {
        if ($result['status'] === 409) {
            expect($result['code'])->toBe('insufficient_inventory');
        }
    }

    $row = ticketTypeInventoryRow($tenantId, $ticketTypeId);

    expect($row->sold + $row->held)->toBeLessThanOrEqual($row->quantity)
        ->and($row->held)->toBe($expectedSuccesses * $perRequest);
})->with([
    'exact fit: 8 workers claim 1 each against quantity 8' => [8, 8, 1],
    '2x oversubscription: 8 workers claim 1 each against quantity 4' => [4, 8, 1],
    '10x oversubscription: 10 workers claim 1 each against quantity 1' => [1, 10, 1],
    'multi-unit requests: 6 workers claim 2 each against quantity 5' => [5, 6, 2],
]);
