<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Models\Hold;
use App\Inventory\Models\PurchaseCounter;
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
 * The purchase-limit simulation (stage-10 plan, TDD sequencing Slice 3,
 * mandated by the master plan's Stage 10 line): N parallel processes
 * holding k tickets each for one customer against max_per_customer = L
 * where N*k > L never push the customer's purchase_counters row past L,
 * exactly floor(L/k) holds succeed, and the Stage 6 inventory counters
 * stay consistent with the winners. Driven through the real HTTP kernel
 * in forked workers, mirroring tests/Concurrency/GaHoldContentionTest.php's
 * own precedent, so each contender runs the full production path
 * including the held-increment guard and the purchase-counter guard
 * inside the same CreateHold transaction.
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
            DB::table('purchase_counters')->where('tenant_id', $tenantId)->delete();
            DB::table('customers')->where('tenant_id', $tenantId)->delete();
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
 * @return array{host: string, tenantId: string, eventId: string, ticketTypeId: string, customerId: string, token: string}
 */
function purchaseLimitFixture(int $limit, int $inventoryQuantity, string $email): array
{
    ['tenant' => $tenant, 'domain' => $domain] = app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create();
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        return ['tenant' => $tenant, 'domain' => $domain];
    });

    ['eventId' => $eventId, 'ticketTypeId' => $ticketTypeId, 'customerId' => $customerId] = app(TenantTransaction::class)->asTenant(
        $tenant->id,
        function () use ($tenant, $limit, $inventoryQuantity, $email): array {
            $event = Event::factory()->create(['tenant_id' => $tenant->id, 'status' => EventStatus::Published]);
            $ticketType = TicketType::factory()->create([
                'tenant_id' => $tenant->id,
                'event_id' => $event->id,
                'max_per_customer' => $limit,
            ]);
            TicketTypeInventory::factory()->create([
                'tenant_id' => $tenant->id,
                'ticket_type_id' => $ticketType->id,
                'quantity' => $inventoryQuantity,
                'held' => 0,
                'sold' => 0,
            ]);
            $customer = Customer::factory()->create([
                'tenant_id' => $tenant->id,
                'email' => $email,
                'password' => 'password',
            ]);

            return ['eventId' => $event->id, 'ticketTypeId' => $ticketType->id, 'customerId' => $customer->id];
        },
    );

    $token = test()->postJson('http://'.$domain->domain.'/v1/auth/customer/token', [
        'email' => $email,
        'password' => 'password',
    ])->json('access_token');

    return [
        'host' => $domain->domain,
        'tenantId' => $tenant->id,
        'eventId' => $eventId,
        'ticketTypeId' => $ticketTypeId,
        'customerId' => $customerId,
        'token' => $token,
    ];
}

/**
 * @return array{status: int, code: string|null, id: string|null}
 */
function handlePurchaseLimitHoldRequest(string $host, string $eventId, string $ticketTypeId, int $quantity, string $token): array
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
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    ], json_encode($payload, JSON_THROW_ON_ERROR));

    $response = app(Kernel::class)->handle($request);

    $body = json_decode((string) $response->getContent(), true);

    return [
        'status' => $response->getStatusCode(),
        'code' => is_array($body) ? ($body['code'] ?? null) : null,
        'id' => is_array($body) ? ($body['id'] ?? null) : null,
    ];
}

/**
 * @return array{status: int, code: string|null}
 */
function handlePurchaseLimitReleaseRequest(string $host, string $holdId, string $token): array
{
    $request = Request::create('http://'.$host.'/v1/storefront/holds/'.$holdId, 'DELETE', [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    ]);

    $response = app(Kernel::class)->handle($request);

    $body = json_decode((string) $response->getContent(), true);

    return [
        'status' => $response->getStatusCode(),
        'code' => is_array($body) ? ($body['code'] ?? null) : null,
    ];
}

function purchaseLimitCounterQuantity(string $tenantId, string $customerId, string $ticketTypeId): int
{
    return (int) app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => PurchaseCounter::query()
            ->where('customer_id', $customerId)
            ->where('ticket_type_id', $ticketTypeId)
            ->value('quantity') ?? 0,
    );
}

function purchaseLimitInventoryRow(string $tenantId, string $ticketTypeId): TicketTypeInventory
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId)->firstOrFail(),
    );
}

it('never exceeds max_per_customer across parallel hold requests for one customer', function (int $limit, int $workers, int $perRequest): void {
    [
        'host' => $host,
        'tenantId' => $tenantId,
        'eventId' => $eventId,
        'ticketTypeId' => $ticketTypeId,
        'customerId' => $customerId,
        'token' => $token,
    ] = purchaseLimitFixture($limit, $workers * $perRequest, 'race@example.com');

    $results = ParallelRunner::run(
        $workers,
        fn (PDO $pdo): array => handlePurchaseLimitHoldRequest($host, $eventId, $ticketTypeId, $perRequest, $token),
    );

    $statuses = collect($results)->pluck('status');

    $expectedSuccesses = intdiv($limit, $perRequest);

    expect($statuses->filter(fn (int $s): bool => $s === 201))->toHaveCount($expectedSuccesses)
        ->and($statuses->reject(fn (int $s): bool => in_array($s, [201, 409], true)))->toBeEmpty();

    foreach ($results as $result) {
        if ($result['status'] === 409) {
            expect($result['code'])->toBe('purchase_limit_exceeded');
        }
    }

    $counter = purchaseLimitCounterQuantity($tenantId, $customerId, $ticketTypeId);
    $expectedQuantity = $expectedSuccesses * $perRequest;

    expect($counter)->toBeLessThanOrEqual($limit)
        ->and($counter)->toBe($expectedQuantity);

    $row = purchaseLimitInventoryRow($tenantId, $ticketTypeId);

    expect($row->held)->toBe($expectedQuantity)
        ->and($row->sold + $row->held)->toBeLessThanOrEqual($row->quantity);
})->with([
    'exact fit: 8 workers claim 1 each against limit 8' => [8, 8, 1],
    '2x oversubscription: 8 workers claim 1 each against limit 4' => [4, 8, 1],
    '10x oversubscription: 10 workers claim 1 each against limit 1' => [1, 10, 1],
    'multi-unit requests: 6 workers claim 2 each against limit 5' => [5, 6, 2],
]);

it('reaches the limit independently for two customers racing at once', function (): void {
    $limit = 3;
    $workersPerCustomer = 6;

    $fixtureA = purchaseLimitFixture($limit, $workersPerCustomer * 2, 'race-a@example.com');
    ['tenantId' => $tenantId, 'eventId' => $eventId, 'ticketTypeId' => $ticketTypeId] = $fixtureA;

    // Same tenant, event, and ticket type; a second customer under the same
    // tenant races independently for its own purchase_counters row.
    $customerB = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Customer::factory()->create([
            'tenant_id' => $tenantId,
            'email' => 'race-b@example.com',
            'password' => 'password',
        ])->id,
    );

    $tokenB = test()->postJson('http://'.$fixtureA['host'].'/v1/auth/customer/token', [
        'email' => 'race-b@example.com',
        'password' => 'password',
    ])->json('access_token');

    $tasks = [];

    for ($i = 0; $i < $workersPerCustomer; $i++) {
        $tasks[] = fn (): array => handlePurchaseLimitHoldRequest($fixtureA['host'], $eventId, $ticketTypeId, 1, $fixtureA['token']);
        $tasks[] = fn (): array => handlePurchaseLimitHoldRequest($fixtureA['host'], $eventId, $ticketTypeId, 1, $tokenB);
    }

    $results = ParallelRunner::runEach(...$tasks);

    $counterA = purchaseLimitCounterQuantity($tenantId, $fixtureA['customerId'], $ticketTypeId);
    $counterB = purchaseLimitCounterQuantity($tenantId, $customerB, $ticketTypeId);

    expect($counterA)->toBe($limit)
        ->and($counterB)->toBe($limit);

    $successes = collect($results)->pluck('status')->filter(fn (int $s): bool => $s === 201);
    expect($successes)->toHaveCount($limit * 2);

    $row = purchaseLimitInventoryRow($tenantId, $ticketTypeId);
    expect($row->held)->toBe($limit * 2);
});

it('converges to the actual outstanding quantity under mixed create-release interleaving', function (): void {
    $limit = 3;

    [
        'host' => $host,
        'tenantId' => $tenantId,
        'eventId' => $eventId,
        'ticketTypeId' => $ticketTypeId,
        'customerId' => $customerId,
        'token' => $token,
    ] = purchaseLimitFixture($limit, 20, 'mixed-race@example.com');

    // Pre-fill the limit with 3 already-active holds (one unit each), so
    // the race mixes 3 releases (freeing headroom) against 5 create
    // attempts (racing for it), whatever order they land in.
    $existingHoldIds = app(TenantTransaction::class)->asTenant($tenantId, function () use ($eventId, $ticketTypeId, $customerId): array {
        $ids = [];

        for ($i = 0; $i < 3; $i++) {
            $ids[] = app(CreateHold::class)(
                CreateHoldData::from([
                    'event_id' => $eventId,
                    'items' => [['ticket_type_id' => $ticketTypeId, 'quantity' => 1]],
                ]),
                $customerId,
            )->id;
        }

        return $ids;
    });

    expect(purchaseLimitCounterQuantity($tenantId, $customerId, $ticketTypeId))->toBe($limit);

    $tasks = [
        fn (): array => handlePurchaseLimitReleaseRequest($host, $existingHoldIds[0], $token),
        fn (): array => handlePurchaseLimitHoldRequest($host, $eventId, $ticketTypeId, 1, $token),
        fn (): array => handlePurchaseLimitReleaseRequest($host, $existingHoldIds[1], $token),
        fn (): array => handlePurchaseLimitHoldRequest($host, $eventId, $ticketTypeId, 1, $token),
        fn (): array => handlePurchaseLimitReleaseRequest($host, $existingHoldIds[2], $token),
        fn (): array => handlePurchaseLimitHoldRequest($host, $eventId, $ticketTypeId, 1, $token),
        fn (): array => handlePurchaseLimitHoldRequest($host, $eventId, $ticketTypeId, 1, $token),
        fn (): array => handlePurchaseLimitHoldRequest($host, $eventId, $ticketTypeId, 1, $token),
    ];

    ParallelRunner::runEach(...$tasks);

    $activeCountedQuantity = (int) app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => DB::table('hold_items')
            ->join('holds', 'holds.id', '=', 'hold_items.hold_id')
            ->where('holds.status', 'active')
            ->where('hold_items.ticket_type_id', $ticketTypeId)
            ->sum('hold_items.counted_quantity'),
    );

    $counter = purchaseLimitCounterQuantity($tenantId, $customerId, $ticketTypeId);

    expect($counter)->toBeLessThanOrEqual($limit)
        ->and($counter)->toBe($activeCountedQuantity);

    $activeHeldQuantity = (int) app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Hold::query()->where('status', 'active')->count(),
    );

    $row = purchaseLimitInventoryRow($tenantId, $ticketTypeId);
    expect($row->held)->toBe($activeHeldQuantity);
});
