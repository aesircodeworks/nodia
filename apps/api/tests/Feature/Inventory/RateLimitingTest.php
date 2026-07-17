<?php

use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-10 plan, TDD sequencing Slice 2 (Feature, first): the
 * `hold_creation` and `browse` named rate limiter tiers (Endpoints
 * "Rate limiting tiers"). Every limit is driven through config/onsale.php
 * overrides rather than the platform defaults, and every window reset is
 * driven through the framework clock rather than real sleeping
 * (stage-10 plan Risks "Fake clock versus Redis TTL"): the array cache
 * store this suite runs under (phpunit.xml CACHE_STORE=array) keys its
 * own expiry off Carbon::now() exactly like Redis's real TTL keys off
 * wall-clock time in every other environment (config('cache.default') is
 * "redis" there via CACHE_STORE), so travelTo advances a window with no
 * real sleeping and no behavior difference from production.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

/**
 * @return array{tenant: Tenant, host: string}
 */
function rateLimitedTenant(): array
{
    return app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create();
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        return ['tenant' => $tenant, 'host' => $domain->domain];
    });
}

/**
 * A well-formed body against an unknown event, so every request short
 * of the tier's own limit reaches the controller and renders
 * event_not_found (404) rather than a validation failure, keeping the
 * throttle status the only variable under test.
 *
 * @return TestResponse<JsonResponse>
 */
function attemptHoldCreation(string $host): TestResponse
{
    return test()->postJson('http://'.$host.'/v1/storefront/holds', [
        'event_id' => (string) Str::uuid7(),
        'items' => [['ticket_type_id' => (string) Str::uuid7(), 'quantity' => 1]],
    ]);
}

/**
 * @return TestResponse<JsonResponse>
 */
function attemptBrowseAvailability(string $host): TestResponse
{
    return test()->getJson('http://'.$host.'/v1/storefront/events/'.Str::uuid7().'/availability');
}

/**
 * Unknown event, so every request short of the tier's own limit reaches the
 * controller and renders event_not_found (404) rather than a validation
 * failure, keeping the throttle status the only variable under test.
 *
 * @return TestResponse<JsonResponse>
 */
function attemptQueueEntry(string $host): TestResponse
{
    return test()->postJson('http://'.$host.'/v1/storefront/events/'.Str::uuid7().'/queue-entries', []);
}

/**
 * @return TestResponse<JsonResponse>
 */
function attemptQueuePoll(string $host): TestResponse
{
    return test()->getJson('http://'.$host.'/v1/storefront/queue-entries/'.Str::uuid7());
}

describe('hold_creation rate limiter tier', function (): void {
    it('returns a 429 request.rate_limited problem document with Retry-After once the ip tier is exceeded', function (): void {
        config(['onsale.rate_limits.hold_creation.ip' => ['max_attempts' => 2, 'decay_seconds' => 60]]);
        ['host' => $host] = rateLimitedTenant();

        attemptHoldCreation($host)->assertStatus(404);
        attemptHoldCreation($host)->assertStatus(404);
        $blocked = attemptHoldCreation($host);

        $blocked->assertStatus(429)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertMatchesProblemSchema()
            ->assertJsonPath('code', 'request.rate_limited');

        expect($blocked->headers->get('Retry-After'))->not->toBeNull();
    });

    it('resets once the fake clock advances past the decay window, with no real sleeping', function (): void {
        config(['onsale.rate_limits.hold_creation.ip' => ['max_attempts' => 1, 'decay_seconds' => 30]]);
        ['host' => $host] = rateLimitedTenant();

        attemptHoldCreation($host)->assertStatus(404);
        attemptHoldCreation($host)->assertStatus(429);

        test()->travelTo(now()->addSeconds(31));

        attemptHoldCreation($host)->assertStatus(404);
    });
});

describe('queue_entry rate limiter tier', function (): void {
    it('returns a 429 request.rate_limited problem document with Retry-After once the tier is exceeded', function (): void {
        config(['onsale.rate_limits.queue_entry' => ['max_attempts' => 2, 'decay_seconds' => 60]]);
        ['host' => $host] = rateLimitedTenant();

        attemptQueueEntry($host)->assertStatus(404);
        attemptQueueEntry($host)->assertStatus(404);
        $blocked = attemptQueueEntry($host);

        $blocked->assertStatus(429)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertMatchesProblemSchema()
            ->assertJsonPath('code', 'request.rate_limited');

        expect($blocked->headers->get('Retry-After'))->not->toBeNull();
    });

    it('resets once the fake clock advances past the decay window, with no real sleeping', function (): void {
        config(['onsale.rate_limits.queue_entry' => ['max_attempts' => 1, 'decay_seconds' => 30]]);
        ['host' => $host] = rateLimitedTenant();

        attemptQueueEntry($host)->assertStatus(404);
        attemptQueueEntry($host)->assertStatus(429);

        test()->travelTo(now()->addSeconds(31));

        attemptQueueEntry($host)->assertStatus(404);
    });
});

describe('queue_poll rate limiter tier', function (): void {
    it('returns a 429 request.rate_limited problem document with Retry-After once the tier is exceeded', function (): void {
        config(['onsale.rate_limits.queue_poll' => ['max_attempts' => 2, 'decay_seconds' => 60]]);
        ['host' => $host] = rateLimitedTenant();

        attemptQueuePoll($host)->assertStatus(404);
        attemptQueuePoll($host)->assertStatus(404);
        $blocked = attemptQueuePoll($host);

        $blocked->assertStatus(429)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertMatchesProblemSchema()
            ->assertJsonPath('code', 'request.rate_limited');

        expect($blocked->headers->get('Retry-After'))->not->toBeNull();
    });

    it('resets once the fake clock advances past the decay window, with no real sleeping', function (): void {
        config(['onsale.rate_limits.queue_poll' => ['max_attempts' => 1, 'decay_seconds' => 30]]);
        ['host' => $host] = rateLimitedTenant();

        attemptQueuePoll($host)->assertStatus(404);
        attemptQueuePoll($host)->assertStatus(429);

        test()->travelTo(now()->addSeconds(31));

        attemptQueuePoll($host)->assertStatus(404);
    });
});

describe('browse rate limiter tier is measurably looser than hold_creation', function (): void {
    it('keeps serving browse requests past the point an equal number of hold_creation requests would already be blocked', function (): void {
        config(['onsale.rate_limits.hold_creation.ip' => ['max_attempts' => 2, 'decay_seconds' => 60]]);
        config(['onsale.rate_limits.browse' => ['max_attempts' => 5, 'decay_seconds' => 60]]);
        ['host' => $host] = rateLimitedTenant();

        attemptHoldCreation($host)->assertStatus(404);
        attemptHoldCreation($host)->assertStatus(404);
        attemptHoldCreation($host)->assertStatus(429);

        attemptBrowseAvailability($host)->assertStatus(404);
        attemptBrowseAvailability($host)->assertStatus(404);
        attemptBrowseAvailability($host)->assertStatus(404);
    });
});
