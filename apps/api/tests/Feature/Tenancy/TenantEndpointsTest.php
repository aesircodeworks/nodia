<?php

use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete(),
    );
});

function createTenantRow(array $attributes = []): Tenant
{
    return app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create($attributes),
    );
}

describe('POST /v1/tenants', function () {
    it('creates a tenant and returns the TenantData wire shape', function () {
        $response = $this->postJson('/v1/tenants', [
            'name' => 'Acme Live',
            'default_locale' => 'pt',
            'supported_locales' => ['pt', 'en'],
            'branding_settings' => ['primary_color' => '#AA0044'],
            'enabled_gateways' => ['fake'],
            'payout_schedule' => ['interval' => 'weekly'],
        ]);

        $response->assertCreated()
            ->assertConformsToOpenApi()
            ->assertJson([
                'name' => 'Acme Live',
                'default_locale' => 'pt',
                'supported_locales' => ['pt', 'en'],
                'branding_settings' => ['primary_color' => '#AA0044'],
                'enabled_gateways' => ['fake'],
                'payout_schedule' => ['interval' => 'weekly'],
            ]);

        $body = $response->json();

        expect(Str::isUuid($body['id']))->toBeTrue()
            ->and($body['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
            ->and($body['updated_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
            ->and(array_keys($body))->toBe([
                'id', 'name', 'branding_settings', 'default_locale', 'supported_locales',
                'enabled_gateways', 'payout_schedule', 'created_at', 'updated_at',
            ]);
    });

    it('applies the documented defaults when the optional fields are absent', function () {
        $this->postJson('/v1/tenants', [
            'name' => 'Bare Tenant',
            'default_locale' => 'en',
            'supported_locales' => ['en'],
        ])
            ->assertCreated()
            ->assertConformsToOpenApi()
            ->assertJson([
                'branding_settings' => ['primary_color' => null, 'logo_url' => null],
                'enabled_gateways' => [],
                'payout_schedule' => null,
            ]);
    });

    it('rejects an invalid payload with a request.validation_failed problem carrying the errors map', function () {
        $response = $this->postJson('/v1/tenants', [
            'name' => '',
            'default_locale' => 'en',
            'supported_locales' => [''],
        ]);

        $response->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed')
            ->assertJsonPath('status', 422);

        expect($response->json('errors'))->toHaveKeys(['name', 'supported_locales.0']);
    });

    it('rejects a default locale outside the supported locales with a default_locale_not_supported problem', function () {
        $this->postJson('/v1/tenants', [
            'name' => 'Acme Live',
            'default_locale' => 'fr',
            'supported_locales' => ['pt', 'en'],
        ])
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'default_locale_not_supported')
            ->assertJsonMissingPath('errors');

        $written = app(TenantTransaction::class)->asPlatform(
            fn () => Tenant::query()->where('name', 'Acme Live')->count(),
        );

        expect($written)->toBe(0);
    });
});

describe('GET /v1/tenants', function () {
    it('returns the standard paginator envelope of TenantData', function () {
        createTenantRow(['name' => 'Envelope Tenant']);

        $response = $this->getJson('/v1/tenants');

        $response->assertOk()->assertConformsToOpenApi();

        $body = $response->json();

        expect(array_keys($body))->toContain('data', 'links', 'meta')
            ->and(collect($body['data'])->pluck('name')->all())->toContain('Envelope Tenant');
    });

    it('filters by name with filter[name]', function () {
        createTenantRow(['name' => 'Alpha Events']);
        createTenantRow(['name' => 'Beta Shows']);

        $this->getJson('/v1/tenants?filter[name]=alpha')
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha Events');
    });

    it('sorts by the allowed sorts in both directions', function (string $sort, array $expected) {
        createTenantRow(['name' => 'B Tenant', 'created_at' => '2026-01-02T00:00:00Z', 'updated_at' => '2026-01-02T00:00:00Z']);
        createTenantRow(['name' => 'A Tenant', 'created_at' => '2026-01-03T00:00:00Z', 'updated_at' => '2026-01-03T00:00:00Z']);

        $names = collect($this->getJson('/v1/tenants?filter[name]=tenant&sort='.$sort)->assertOk()->json('data'))
            ->pluck('name')
            ->all();

        expect($names)->toBe($expected);
    })->with([
        'name' => ['name', ['A Tenant', 'B Tenant']],
        '-name' => ['-name', ['B Tenant', 'A Tenant']],
        'created_at' => ['created_at', ['B Tenant', 'A Tenant']],
        '-created_at' => ['-created_at', ['A Tenant', 'B Tenant']],
    ]);

    it('rejects an unknown filter with an invalid_query_parameter problem', function () {
        $this->getJson('/v1/tenants?filter[unknown]=x')
            ->assertBadRequest()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'invalid_query_parameter')
            ->assertJsonPath('status', 400);
    });

    it('rejects an unknown sort with an invalid_query_parameter problem', function () {
        $this->getJson('/v1/tenants?sort=payout_schedule')
            ->assertBadRequest()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'invalid_query_parameter');
    });
});

describe('GET /v1/tenants/{tenant}', function () {
    it('returns the tenant', function () {
        $tenant = createTenantRow(['name' => 'Readable Tenant']);

        $this->getJson('/v1/tenants/'.$tenant->id)
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonPath('id', $tenant->id)
            ->assertJsonPath('name', 'Readable Tenant');
    });

    it('returns a tenant_not_found problem for an unknown tenant id', function () {
        $this->getJson('/v1/tenants/'.Str::uuid7())
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'tenant_not_found')
            ->assertJsonPath('status', 404);
    });

    it('returns a request.not_found problem for a malformed tenant id that matches no route', function () {
        $this->getJson('/v1/tenants/not-a-uuid')
            ->assertNotFound()
            ->assertMatchesProblemSchema()
            ->assertJsonPath('code', 'request.not_found');
    });
});

describe('PATCH /v1/tenants/{tenant}', function () {
    it('updates branding and locale fields through UpdateBranding', function () {
        $tenant = createTenantRow([
            'name' => 'Before',
            'default_locale' => 'en',
            'supported_locales' => ['en'],
        ]);

        $this->patchJson('/v1/tenants/'.$tenant->id, [
            'name' => 'After',
            'branding_settings' => ['primary_color' => '#001122'],
            'default_locale' => 'pt',
            'supported_locales' => ['pt', 'en'],
        ])
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJson([
                'id' => $tenant->id,
                'name' => 'After',
                'branding_settings' => ['primary_color' => '#001122'],
                'default_locale' => 'pt',
                'supported_locales' => ['pt', 'en'],
            ]);
    });

    it('updates the enabled gateways through ConfigureGateways', function () {
        $tenant = createTenantRow(['enabled_gateways' => []]);

        $this->patchJson('/v1/tenants/'.$tenant->id, ['enabled_gateways' => ['fake', 'other']])
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonPath('enabled_gateways', ['fake', 'other']);

        $fresh = app(TenantTransaction::class)->asPlatform(fn () => Tenant::query()->find($tenant->id));

        expect($fresh->enabled_gateways)->toBe(['fake', 'other']);
    });

    it('updates the opaque payout schedule', function () {
        $tenant = createTenantRow(['payout_schedule' => null]);

        $this->patchJson('/v1/tenants/'.$tenant->id, ['payout_schedule' => ['interval' => 'monthly']])
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonPath('payout_schedule.interval', 'monthly');
    });

    it('leaves fields absent from the payload untouched', function () {
        $tenant = createTenantRow([
            'name' => 'Untouched',
            'default_locale' => 'en',
            'supported_locales' => ['en'],
            'enabled_gateways' => ['fake'],
        ]);

        $this->patchJson('/v1/tenants/'.$tenant->id, ['name' => 'Renamed'])
            ->assertOk()
            ->assertJson([
                'name' => 'Renamed',
                'default_locale' => 'en',
                'supported_locales' => ['en'],
                'enabled_gateways' => ['fake'],
            ]);
    });

    it('returns a tenant_not_found problem for an unknown tenant id', function () {
        $this->patchJson('/v1/tenants/'.Str::uuid7(), ['name' => 'Ghost'])
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'tenant_not_found');
    });

    it('rejects an invalid payload with a request.validation_failed problem', function () {
        $tenant = createTenantRow();

        $this->patchJson('/v1/tenants/'.$tenant->id, ['enabled_gateways' => ['']])
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');
    });

    it('re-applies the locale membership invariant with a default_locale_not_supported problem', function () {
        $tenant = createTenantRow([
            'default_locale' => 'en',
            'supported_locales' => ['en'],
        ]);

        $this->patchJson('/v1/tenants/'.$tenant->id, ['default_locale' => 'fr'])
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'default_locale_not_supported');
    });
});
