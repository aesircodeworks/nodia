<?php

use App\EventCatalog\Data\OnSalePolicyData;
use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\Inventory\Support\OnSaleQueue;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-10 plan, TDD sequencing Slice 5 (Feature, first, mandated), task
 * breakdown item 8: "with rate R and W waiting entrants, one gatekeeper
 * tick admits min(R-per-interval, W) in arrival order; the poll endpoint
 * then returns admitted with a token and expiry; an entrant polling
 * after token expiry (fake clock) gets 404; per-event rate honored
 * across two flagged events of different tenants in one tick (cross-
 * tenant config read via the section 4.3 role, following the Stage 4
 * worker precedent)." Drives `php artisan onsale:gatekeeper`
 * (App\Console\Commands\GatekeeperCommand) exactly as the scheduler
 * runs it, then the real poll endpoint, mirroring tests/Feature/
 * Inventory/QueueEntryEndpointsTest.php's own fixture pattern.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    $redis = Redis::connection();

    foreach ($tenantIds as $tenantId) {
        $eventIds = app(TenantTransaction::class)->asTenant(
            $tenantId,
            fn () => Event::query()->where('tenant_id', $tenantId)->pluck('id')->all(),
        );

        foreach ($eventIds as $eventId) {
            $redis->del(OnSaleQueue::waitingKey($tenantId, $eventId));
            $redis->del(OnSaleQueue::admittedKey($tenantId, $eventId));
            $redis->srem('onsale:active', OnSaleQueue::activeMember($tenantId, $eventId));

            foreach ($redis->keys('onsale:{'.$tenantId.':'.$eventId.'}:budget:*') as $key) {
                $redis->del($key);
            }
        }

        foreach ($redis->keys('onsale:'.$tenantId.':entrant:*') as $key) {
            $redis->del($key);
        }

        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
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
 * @return array{tenant: Tenant, host: string}
 */
function gatekeeperTenant(): array
{
    return app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create();
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        return ['tenant' => $tenant, 'host' => $domain->domain];
    });
}

function gatekeeperFlaggedEvent(string $tenantId, ?int $admissionRatePerMinute): Event
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Event::factory()->create([
            'tenant_id' => $tenantId,
            'status' => EventStatus::Published,
            'on_sale_policy' => new OnSalePolicyData(highDemand: true, admissionRatePerMinute: $admissionRatePerMinute),
        ]),
    );
}

/**
 * @return list<string>
 */
function gatekeeperJoinEntrants(string $host, string $eventId, int $count, CarbonInterface $start): array
{
    $ids = [];

    foreach (range(1, $count) as $i) {
        test()->travelTo($start->copy()->addMilliseconds($i));

        $ids[] = test()->postJson('http://'.$host.'/v1/storefront/events/'.$eventId.'/queue-entries', [])->json('id');
    }

    return $ids;
}

it('admits min(rate, waiting) entrants in arrival order on one gatekeeper tick', function (): void {
    ['tenant' => $tenant, 'host' => $host] = gatekeeperTenant();
    $event = gatekeeperFlaggedEvent($tenant->id, 3);

    $entrantIds = gatekeeperJoinEntrants($host, $event->id, 5, now());

    Artisan::call('onsale:gatekeeper');

    foreach (array_slice($entrantIds, 0, 3) as $entrantId) {
        $entry = test()->getJson('http://'.$host.'/v1/storefront/queue-entries/'.$entrantId)
            ->assertStatus(200)
            ->assertConformsToOpenApi()
            ->assertJsonPath('status', 'admitted')
            ->assertJsonPath('position', null);

        expect($entry->json('admission_token'))->not->toBeNull()
            ->and($entry->json('admission_expires_at'))->not->toBeNull();
    }

    test()->getJson('http://'.$host.'/v1/storefront/queue-entries/'.$entrantIds[3])
        ->assertStatus(200)
        ->assertJsonPath('status', 'waiting')
        ->assertJsonPath('position', 1);

    test()->getJson('http://'.$host.'/v1/storefront/queue-entries/'.$entrantIds[4])
        ->assertStatus(200)
        ->assertJsonPath('status', 'waiting')
        ->assertJsonPath('position', 2);
});

it('returns 404 queue_entry_not_found once the admitted token has expired on the fake clock', function (): void {
    config(['onsale.admission_token.ttl_seconds' => 60]);

    ['tenant' => $tenant, 'host' => $host] = gatekeeperTenant();
    $event = gatekeeperFlaggedEvent($tenant->id, 5);

    $entrantIds = gatekeeperJoinEntrants($host, $event->id, 1, now());

    Artisan::call('onsale:gatekeeper');

    test()->getJson('http://'.$host.'/v1/storefront/queue-entries/'.$entrantIds[0])
        ->assertStatus(200)
        ->assertJsonPath('status', 'admitted');

    test()->travelTo(now()->addSeconds(61));

    test()->getJson('http://'.$host.'/v1/storefront/queue-entries/'.$entrantIds[0])
        ->assertStatus(404)
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'queue_entry_not_found');
});

it('honors each flagged event\'s own per-minute rate across two tenants in one tick', function (): void {
    ['tenant' => $tenantA, 'host' => $hostA] = gatekeeperTenant();
    ['tenant' => $tenantB, 'host' => $hostB] = gatekeeperTenant();

    $eventA = gatekeeperFlaggedEvent($tenantA->id, 2);
    $eventB = gatekeeperFlaggedEvent($tenantB->id, 4);

    $entrantIdsA = gatekeeperJoinEntrants($hostA, $eventA->id, 5, now());
    $entrantIdsB = gatekeeperJoinEntrants($hostB, $eventB->id, 5, now());

    Artisan::call('onsale:gatekeeper');

    $admittedA = collect($entrantIdsA)->filter(
        fn (string $id): bool => test()->getJson('http://'.$hostA.'/v1/storefront/queue-entries/'.$id)->json('status') === 'admitted',
    );

    $admittedB = collect($entrantIdsB)->filter(
        fn (string $id): bool => test()->getJson('http://'.$hostB.'/v1/storefront/queue-entries/'.$id)->json('status') === 'admitted',
    );

    expect($admittedA)->toHaveCount(2)
        ->and($admittedB)->toHaveCount(4);
});

it('a tick with no active queues admits nothing and exits cleanly', function (): void {
    Artisan::call('onsale:gatekeeper');

    expect(Artisan::output())->toContain('Admitted 0 entrant(s).');
});
