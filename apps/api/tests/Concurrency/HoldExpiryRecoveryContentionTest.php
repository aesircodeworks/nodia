<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Actions\ReleaseExpiredHolds;
use App\Inventory\Actions\ReleaseHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Enums\HoldStatus;
use App\Inventory\Models\Hold;
use App\Inventory\Models\TicketTypeInventory;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;

/**
 * The expiry-recovery simulation (stage-06 plan, Slice 3, task breakdown
 * item 6; TDD sequencing "expiry recovery concurrency simulation: holds
 * created, clock past TTL, sweeper concurrent with new hold creation,
 * availability returns exactly to quantity - sold, late conversions
 * fail"). Also proves the exactly-one-of-HoldReleased/HoldExpired
 * guarantee (Domain events table): an explicit release racing the sweeper
 * for the same hold ends with exactly one terminal status and exactly one
 * of the two events recorded, never both, never zero.
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

it('never double-reconciles or double-records when an explicit release races the sweeper', function (): void {
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $now = now();
    Date::setTestNow($now);

    $holdId = app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): string {
        $event = Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);

        TicketTypeInventory::factory()->create([
            'tenant_id' => $tenantId,
            'ticket_type_id' => $ticketType->id,
            'quantity' => 5,
            'held' => 0,
            'sold' => 0,
        ]);

        return app(CreateHold::class)(
            CreateHoldData::from([
                'event_id' => $event->id,
                'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 5]],
            ]),
            null,
        )->id;
    });

    Date::setTestNow($now->copy()->addMinutes(11));

    ParallelRunner::runEach(
        fn (): mixed => Date::setTestNow($now->copy()->addMinutes(11)) ?? app(TenantTransaction::class)->asTenant(
            $tenantId,
            fn () => app(ReleaseHold::class)($holdId),
        ),
        fn (): int => Date::setTestNow($now->copy()->addMinutes(11)) ?? app(ReleaseExpiredHolds::class)(),
    );

    $hold = app(TenantTransaction::class)->asTenant($tenantId, fn () => Hold::query()->findOrFail($holdId));
    $inventory = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', DB::table('hold_items')->where('hold_id', $holdId)->value('ticket_type_id'))->first(),
    );
    $releasedCount = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => OutboxEvent::query()->where('tenant_id', $tenantId)->where('type', 'HoldReleased')->count(),
    );
    $expiredCount = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => OutboxEvent::query()->where('tenant_id', $tenantId)->where('type', 'HoldExpired')->count(),
    );

    expect($hold->status)->toBeIn([HoldStatus::Released, HoldStatus::Expired])
        ->and($inventory->held)->toBe(0)
        ->and($releasedCount + $expiredCount)->toBe(1);

    Date::setTestNow();
});

it('recovers availability to exactly quantity - sold when the sweeper races new hold creation', function (): void {
    ['tenant' => $tenant, 'domain' => $domain] = app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create();
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        return ['tenant' => $tenant, 'domain' => $domain];
    });

    $now = now();
    Date::setTestNow($now);

    [$eventId, $ticketTypeId] = app(TenantTransaction::class)->asTenant($tenant->id, function () use ($tenant): array {
        $event = Event::factory()->create(['tenant_id' => $tenant->id, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

        TicketTypeInventory::factory()->create([
            'tenant_id' => $tenant->id,
            'ticket_type_id' => $ticketType->id,
            'quantity' => 8,
            'held' => 0,
            'sold' => 0,
        ]);

        // Eight units already held by a stale hold that will have expired
        // by the time the sweeper and the new contenders below run.
        app(CreateHold::class)(
            CreateHoldData::from([
                'event_id' => $event->id,
                'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 8]],
            ]),
            null,
        );

        return [$event->id, $ticketType->id];
    });

    Date::setTestNow($now->copy()->addMinutes(11));

    $results = ParallelRunner::runEach(
        fn (): int => Date::setTestNow($now->copy()->addMinutes(11)) ?? app(ReleaseExpiredHolds::class)(),
        fn (): array => Date::setTestNow($now->copy()->addMinutes(11)) ?? handleContentionHoldRequest($domain->domain, $eventId, $ticketTypeId, 3),
        fn (): array => Date::setTestNow($now->copy()->addMinutes(11)) ?? handleContentionHoldRequest($domain->domain, $eventId, $ticketTypeId, 3),
        fn (): array => Date::setTestNow($now->copy()->addMinutes(11)) ?? handleContentionHoldRequest($domain->domain, $eventId, $ticketTypeId, 3),
    );

    // The stale hold's own eight units are only actually freed once the
    // sweeper worker's transaction commits; the three new-hold workers race
    // it, so however many of them land before that commit legitimately see
    // insufficient_inventory rather than overselling the still-held stale
    // units. What must hold regardless of interleaving: the counter never
    // oversells, and by the time every worker has joined, availability
    // exactly reflects the surviving holds (the stale one is gone, only the
    // new successes remain).
    $successCount = collect($results)
        ->slice(1)
        ->filter(fn (array $result): bool => $result['status'] === 201)
        ->count();

    foreach (array_slice($results, 1) as $result) {
        if ($result['status'] !== 201) {
            expect($result['code'])->toBe('insufficient_inventory');
        }
    }

    $row = app(TenantTransaction::class)->asTenant(
        $tenant->id,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId)->firstOrFail(),
    );

    expect($row->sold)->toBe(0)
        ->and($row->held)->toBe($successCount * 3)
        ->and($row->quantity - $row->sold - $row->held)->toBe(8 - $successCount * 3)
        ->and($row->sold + $row->held)->toBeLessThanOrEqual($row->quantity);

    Date::setTestNow();
});

/**
 * @return array{status: int, code: string|null}
 */
function handleContentionHoldRequest(string $host, string $eventId, string $ticketTypeId, int $quantity): array
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
