<?php

use App\EventCatalog\Data\OnSalePolicyData;
use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\Inventory\Support\ChallengeVerifier;
use App\Inventory\Support\FakeChallengeVerifier;
use App\Inventory\Support\OnSaleQueue;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-10 plan, TDD sequencing Slice 4, task breakdown item 7: POST
 * /v1/storefront/events/{event}/queue-entries and GET /v1/storefront/
 * queue-entries/{entry}, mirroring tests/Feature/Inventory/
 * HoldEndpointsTest.php's own Host-resolution fixture pattern. Redis
 * queue state (App\Inventory\Support\OnSaleQueue) is purged in afterEach
 * for every event created by this suite's own tenants, ahead of the
 * event rows themselves, so no test leaks state into another.
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
            $redis->srem('onsale:active', OnSaleQueue::activeMember($tenantId, $eventId));
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
function queueTenant(): array
{
    return app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create();
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        return ['tenant' => $tenant, 'host' => $domain->domain];
    });
}

function queueFlaggedEvent(string $tenantId, bool $challengeRequired = false): Event
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Event::factory()->create([
            'tenant_id' => $tenantId,
            'status' => EventStatus::Published,
            'on_sale_policy' => new OnSalePolicyData(highDemand: true, admissionRatePerMinute: null, challengeRequired: $challengeRequired),
        ]),
    );
}

function queueUnflaggedEvent(string $tenantId): Event
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]),
    );
}

describe('POST /v1/storefront/events/{event}/queue-entries', function (): void {
    it('joins the waiting room for a flagged event, returning 201 waiting at position 1', function (): void {
        ['tenant' => $tenant, 'host' => $host] = queueTenant();
        $event = queueFlaggedEvent($tenant->id);

        $response = $this->postJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/queue-entries', []);

        $response->assertStatus(201)->assertConformsToOpenApi();

        $response->assertJsonPath('event_id', $event->id)
            ->assertJsonPath('status', 'waiting')
            ->assertJsonPath('position', 1)
            ->assertJsonPath('admission_token', null)
            ->assertJsonPath('admission_expires_at', null);

        expect(Str::isUuid($response->json('id')))->toBeTrue();
    });

    it('places a second entrant at position 2', function (): void {
        ['tenant' => $tenant, 'host' => $host] = queueTenant();
        $event = queueFlaggedEvent($tenant->id);

        $this->travelTo(now());
        $this->postJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/queue-entries', [])
            ->assertStatus(201)
            ->assertJsonPath('position', 1);

        $this->travelTo(now()->addMillisecond());
        $this->postJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/queue-entries', [])
            ->assertStatus(201)
            ->assertJsonPath('position', 2);
    });

    it('returns queue_not_active for an event not flagged high-demand', function (): void {
        ['tenant' => $tenant, 'host' => $host] = queueTenant();
        $event = queueUnflaggedEvent($tenant->id);

        $this->postJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/queue-entries', [])
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'queue_not_active');
    });

    it('returns event_not_found for an unknown event', function (): void {
        ['host' => $host] = queueTenant();

        $this->postJson('http://'.$host.'/v1/storefront/events/'.Str::uuid7().'/queue-entries', [])
            ->assertStatus(404)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'event_not_found');
    });

    it('returns event_not_found for a draft (unpublished) flagged event', function (): void {
        ['tenant' => $tenant, 'host' => $host] = queueTenant();
        $event = app(TenantTransaction::class)->asTenant(
            $tenant->id,
            fn () => Event::factory()->create([
                'tenant_id' => $tenant->id,
                'status' => EventStatus::Draft,
                'on_sale_policy' => new OnSalePolicyData(highDemand: true),
            ]),
        );

        $this->postJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/queue-entries', [])
            ->assertStatus(404)
            ->assertJsonPath('code', 'event_not_found');
    });

    it('returns request.validation_failed for a non-string challenge_response', function (): void {
        ['tenant' => $tenant, 'host' => $host] = queueTenant();
        $event = queueFlaggedEvent($tenant->id);

        $this->postJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/queue-entries', [
            'challenge_response' => 42,
        ])
            ->assertStatus(422)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');
    });

    describe('challenge matrix', function (): void {
        beforeEach(function (): void {
            app()->instance(ChallengeVerifier::class, new FakeChallengeVerifier);
        });

        it('returns challenge_required when the policy requires one and none is supplied', function (): void {
            ['tenant' => $tenant, 'host' => $host] = queueTenant();
            $event = queueFlaggedEvent($tenant->id, challengeRequired: true);

            $this->postJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/queue-entries', [])
                ->assertStatus(403)
                ->assertConformsToOpenApi()
                ->assertJsonPath('code', 'challenge_required');
        });

        it('returns challenge_failed when the verifier rejects the supplied response', function (): void {
            ['tenant' => $tenant, 'host' => $host] = queueTenant();
            $event = queueFlaggedEvent($tenant->id, challengeRequired: true);

            $this->postJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/queue-entries', [
                'challenge_response' => 'wrong',
            ])
                ->assertStatus(403)
                ->assertConformsToOpenApi()
                ->assertJsonPath('code', 'challenge_failed');
        });

        it('joins the waiting room on a verified challenge response', function (): void {
            ['tenant' => $tenant, 'host' => $host] = queueTenant();
            $event = queueFlaggedEvent($tenant->id, challengeRequired: true);

            $this->postJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/queue-entries', [
                'challenge_response' => FakeChallengeVerifier::VALID_RESPONSE,
            ])
                ->assertStatus(201)
                ->assertConformsToOpenApi()
                ->assertJsonPath('status', 'waiting')
                ->assertJsonPath('position', 1);
        });
    });
});

describe('GET /v1/storefront/queue-entries/{entry}', function (): void {
    it('returns the entrant with its current position', function (): void {
        ['tenant' => $tenant, 'host' => $host] = queueTenant();
        $event = queueFlaggedEvent($tenant->id);

        $created = $this->postJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/queue-entries', [])->json();

        $this->getJson('http://'.$host.'/v1/storefront/queue-entries/'.$created['id'])
            ->assertStatus(200)
            ->assertConformsToOpenApi()
            ->assertJsonPath('id', $created['id'])
            ->assertJsonPath('event_id', $event->id)
            ->assertJsonPath('status', 'waiting')
            ->assertJsonPath('position', 1)
            ->assertJsonPath('admission_token', null)
            ->assertJsonPath('admission_expires_at', null);
    });

    it('returns queue_entry_not_found for an unknown entrant', function (): void {
        ['host' => $host] = queueTenant();

        $this->getJson('http://'.$host.'/v1/storefront/queue-entries/'.Str::uuid7())
            ->assertStatus(404)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'queue_entry_not_found');
    });

    it('returns queue_entry_not_found for an entrant created under a different tenant host (endpoint-level isolation)', function (): void {
        ['tenant' => $tenantA, 'host' => $hostA] = queueTenant();
        ['host' => $hostB] = queueTenant();

        $eventA = queueFlaggedEvent($tenantA->id);

        $created = $this->postJson('http://'.$hostA.'/v1/storefront/events/'.$eventA->id.'/queue-entries', [])->json();

        $this->getJson('http://'.$hostB.'/v1/storefront/queue-entries/'.$created['id'])
            ->assertStatus(404)
            ->assertJsonPath('code', 'queue_entry_not_found');
    });
});
