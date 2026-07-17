<?php

use App\EventCatalog\Models\Venue;
use App\Identity\Capability;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;
use Tests\Support\TenantStaff;

/*
 * Stage-05a plan, task breakdown item 3 (TDD slice 1): venue admin CRUD
 * under the tenancy.admin group. Both reading and mutating require a
 * capability (events.view and events.manage respectively), unlike
 * roles.php's reads. The default bearer below holds both so every test
 * that does not deliberately probe a missing capability can exercise
 * either half of the surface; tests/Feature/EventCatalog/
 * CatalogAuthorizationMatrixTest.php already proved the capability wiring
 * itself through probe routes ahead of this file (task-01 journal).
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $this->otherTenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->withHeaders([
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, [Capability::EventsView, Capability::EventsManage]),
        'X-Tenant-Id' => $this->tenantId,
    ]);
});

afterEach(function (): void {
    foreach ([$this->tenantId, $this->otherTenantId] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('memberships')->where('tenant_id', $tenantId)->delete();
            DB::table('venues')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    User::query()->delete();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function makeVenueRow(string $tenantId, array $attributes = []): Venue
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Venue::factory()->create(['tenant_id' => $tenantId, ...$attributes]),
    );
}

describe('POST /v1/venues', function () {
    it('creates a venue and returns the VenueData wire shape', function () {
        $response = $this->postJson('/v1/venues', [
            'name' => 'Grand Arena',
            'address' => '123 Main St',
            'city' => 'Austin',
            'country' => 'US',
            'capacity' => 5000,
        ]);

        $response->assertCreated()
            ->assertConformsToOpenApi()
            ->assertJson([
                'tenant_id' => $this->tenantId,
                'name' => 'Grand Arena',
                'address' => '123 Main St',
                'city' => 'Austin',
                'country' => 'US',
                'capacity' => 5000,
            ]);

        $body = $response->json();

        expect(Str::isUuid($body['id']))->toBeTrue()
            ->and($body['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
            ->and($body['updated_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
            ->and(array_keys($body))->toBe([
                'id', 'tenant_id', 'name', 'address', 'city', 'country', 'capacity', 'created_at', 'updated_at',
            ]);
    });

    it('rejects an invalid payload with a request.validation_failed problem carrying the errors map', function () {
        $response = $this->postJson('/v1/venues', [
            'name' => '',
            'address' => '123 Main St',
            'city' => 'Austin',
            'country' => 'US',
            'capacity' => 5000,
        ]);

        $response->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');

        expect($response->json('errors'))->toHaveKey('name');
    });

    it('rejects a country code that is not ISO 3166-1 alpha-2', function () {
        $this->postJson('/v1/venues', [
            'name' => 'Grand Arena',
            'address' => '123 Main St',
            'city' => 'Austin',
            'country' => 'USA',
            'capacity' => 5000,
        ])
            ->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');
    });

    it('rejects a non-positive capacity', function (int $capacity) {
        $this->postJson('/v1/venues', [
            'name' => 'Grand Arena',
            'address' => '123 Main St',
            'city' => 'Austin',
            'country' => 'US',
            'capacity' => $capacity,
        ])
            ->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');
    })->with([0, -1]);

    it('rejects a request with no bearer', function () {
        $this->withoutToken()
            ->postJson('/v1/venues', ['name' => 'X'], ['X-Tenant-Id' => $this->tenantId])
            ->assertUnauthorized()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'auth.unauthenticated');
    });

    it('rejects a bearer lacking events.manage with missing_capability', function () {
        $this->withHeaders(['Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::EventsView)])
            ->postJson('/v1/venues', [
                'name' => 'Grand Arena',
                'address' => '123 Main St',
                'city' => 'Austin',
                'country' => 'US',
                'capacity' => 5000,
            ])
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });
});

describe('GET /v1/venues', function () {
    it('returns the standard paginator envelope of VenueData', function () {
        makeVenueRow($this->tenantId, ['name' => 'Envelope Arena']);

        $response = $this->getJson('/v1/venues');

        $response->assertOk()->assertConformsToOpenApi();

        $body = $response->json();

        expect(array_keys($body))->toContain('data', 'links', 'meta')
            ->and(collect($body['data'])->pluck('name')->all())->toContain('Envelope Arena');
    });

    it('never returns a foreign tenant\'s venue', function () {
        makeVenueRow($this->tenantId, ['name' => 'Own Arena']);
        makeVenueRow($this->otherTenantId, ['name' => 'Foreign Arena']);

        $names = collect($this->getJson('/v1/venues')->assertOk()->json('data'))->pluck('name');

        expect($names)->toContain('Own Arena')
            ->and($names)->not->toContain('Foreign Arena');
    });

    it('filters by name with filter[name]', function () {
        makeVenueRow($this->tenantId, ['name' => 'Alpha Hall']);
        makeVenueRow($this->tenantId, ['name' => 'Beta Hall']);

        $this->getJson('/v1/venues?filter[name]=alpha')
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha Hall');
    });

    it('filters by city with filter[city]', function () {
        makeVenueRow($this->tenantId, ['name' => 'Venue One', 'city' => 'Austin']);
        makeVenueRow($this->tenantId, ['name' => 'Venue Two', 'city' => 'Dallas']);

        $this->getJson('/v1/venues?filter[city]=austin')
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Venue One');
    });

    it('sorts by the allowed sorts in both directions', function (string $sort, array $expected) {
        makeVenueRow($this->tenantId, ['name' => 'B Venue', 'created_at' => '2026-01-02T00:00:00Z', 'updated_at' => '2026-01-02T00:00:00Z']);
        makeVenueRow($this->tenantId, ['name' => 'A Venue', 'created_at' => '2026-01-03T00:00:00Z', 'updated_at' => '2026-01-03T00:00:00Z']);

        $names = collect($this->getJson('/v1/venues?filter[name]=Venue&sort='.$sort)->assertOk()->json('data'))
            ->pluck('name')
            ->all();

        expect($names)->toBe($expected);
    })->with([
        'name' => ['name', ['A Venue', 'B Venue']],
        '-name' => ['-name', ['B Venue', 'A Venue']],
        'created_at' => ['created_at', ['B Venue', 'A Venue']],
        '-created_at' => ['-created_at', ['A Venue', 'B Venue']],
    ]);

    it('rejects an unknown filter with an invalid_query_parameter problem', function () {
        $this->getJson('/v1/venues?filter[unknown]=x')
            ->assertBadRequest()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'invalid_query_parameter');
    });

    it('rejects an unknown sort with an invalid_query_parameter problem', function () {
        $this->getJson('/v1/venues?sort=capacity')
            ->assertBadRequest()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'invalid_query_parameter');
    });

    it('rejects a request with no bearer', function () {
        $this->withoutToken()
            ->getJson('/v1/venues', ['X-Tenant-Id' => $this->tenantId])
            ->assertUnauthorized()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'auth.unauthenticated');
    });

    it('rejects a bearer with no membership in the asserted tenant', function () {
        $strangerToken = StaffTokens::issue(User::factory()->create());

        $this->withHeaders(['Authorization' => 'Bearer '.$strangerToken])
            ->getJson('/v1/venues')
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'tenant_access_denied');
    });

    it('rejects a bearer lacking events.view with missing_capability', function () {
        $this->withHeaders(['Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::OrdersView)])
            ->getJson('/v1/venues')
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });
});

describe('GET /v1/venues/{venue}', function () {
    it('returns the venue', function () {
        $venue = makeVenueRow($this->tenantId, ['name' => 'Readable Venue']);

        $this->getJson('/v1/venues/'.$venue->id)
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonPath('id', $venue->id)
            ->assertJsonPath('name', 'Readable Venue');
    });

    it('returns a request.not_found problem for a foreign tenant\'s venue', function () {
        $venue = makeVenueRow($this->otherTenantId, ['name' => 'Foreign Venue']);

        $this->getJson('/v1/venues/'.$venue->id)
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('returns a request.not_found problem for an unknown venue id', function () {
        $this->getJson('/v1/venues/'.Str::uuid7())
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('returns a request.not_found problem for a malformed venue id that matches no route', function () {
        $this->getJson('/v1/venues/not-a-uuid')
            ->assertNotFound()
            ->assertMatchesProblemSchema()
            ->assertJsonPath('code', 'request.not_found');
    });
});

describe('PATCH /v1/venues/{venue}', function () {
    it('updates the given fields', function () {
        $venue = makeVenueRow($this->tenantId, ['name' => 'Before', 'capacity' => 100]);

        $this->patchJson('/v1/venues/'.$venue->id, ['name' => 'After', 'capacity' => 200])
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJson(['name' => 'After', 'capacity' => 200]);
    });

    it('leaves fields absent from the payload untouched', function () {
        $venue = makeVenueRow($this->tenantId, ['name' => 'Untouched', 'city' => 'Austin']);

        $this->patchJson('/v1/venues/'.$venue->id, ['city' => 'Dallas'])
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJson(['name' => 'Untouched', 'city' => 'Dallas']);
    });

    it('rejects a country code that is not ISO 3166-1 alpha-2', function () {
        $venue = makeVenueRow($this->tenantId);

        $this->patchJson('/v1/venues/'.$venue->id, ['country' => 'ZZ'])
            ->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');
    });

    it('rejects a non-positive capacity', function () {
        $venue = makeVenueRow($this->tenantId);

        $this->patchJson('/v1/venues/'.$venue->id, ['capacity' => 0])
            ->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');
    });

    it('returns a request.not_found problem for a foreign tenant\'s venue', function () {
        $venue = makeVenueRow($this->otherTenantId);

        $this->patchJson('/v1/venues/'.$venue->id, ['name' => 'Hijacked'])
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('rejects a request with no bearer', function () {
        $venue = makeVenueRow($this->tenantId);

        $this->withoutToken()
            ->patchJson('/v1/venues/'.$venue->id, ['name' => 'X'], ['X-Tenant-Id' => $this->tenantId])
            ->assertUnauthorized()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'auth.unauthenticated');
    });

    it('rejects a bearer lacking events.manage with missing_capability', function () {
        $venue = makeVenueRow($this->tenantId);

        $this->withHeaders(['Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::EventsView)])
            ->patchJson('/v1/venues/'.$venue->id, ['name' => 'Renamed'])
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });
});
