<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Actions\CreateTenant;
use App\Tenancy\Data\CreateTenantData;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PlatformStaff;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-04 plan, Slice 6 (task-14): TenantCreated producer on CreateTenant.
 * Successful tenant creation over HTTP persists exactly one outbox row with
 * the sentinel platform tenant in the envelope; failure paths record nothing.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->sentinel = config()->string('tenancy.platform_tenant_id');

    $this->withHeaders(['Authorization' => 'Bearer '.PlatformStaff::token()]);
});

afterEach(function (): void {
    $sentinel = $this->sentinel;

    app(TenantTransaction::class)->asTenant($sentinel, function () use ($sentinel): void {
        DB::table('outbox_deliveries')->where('tenant_id', $sentinel)->delete();
        DB::table('outbox_events')->where('tenant_id', $sentinel)->delete();
        DB::table('memberships')->where('tenant_id', $sentinel)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });

    User::query()->delete();
});

it('persists exactly one TenantCreated outbox row on a successful create over HTTP', function () {
    $correlationId = 'tenant-created-correlation-'.Str::uuid7()->toString();

    $response = $this->withHeaders(['X-Correlation-Id' => $correlationId])
        ->postJson('/v1/tenants', [
            'name' => 'Outbox Tenant Co',
            'default_locale' => 'pt',
            'supported_locales' => ['pt', 'en'],
        ]);

    $response->assertCreated();

    $tenantId = $response->json('id');

    $rows = app(TenantTransaction::class)->asTenant(
        $this->sentinel,
        fn () => OutboxEvent::query()->where('type', 'TenantCreated')->get(),
    );

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    expect($row->type)->toBe('TenantCreated')
        ->and($row->aggregate_type)->toBe('tenant')
        ->and($row->aggregate_id)->toBe($tenantId)
        ->and($row->tenant_id)->toBe($this->sentinel)
        ->and($row->correlation_id)->toBe($correlationId)
        ->and($row->payload)->toMatchArray([
            'tenant_id' => $tenantId,
            'name' => 'Outbox Tenant Co',
            'default_locale' => 'pt',
        ])
        ->and(array_keys($row->payload))->toEqualCanonicalizing([
            'tenant_id',
            'name',
            'default_locale',
        ]);
});

it('records nothing when the create request fails validation', function () {
    $this->postJson('/v1/tenants', [
        'name' => '',
        'default_locale' => 'en',
        'supported_locales' => [''],
    ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'request.validation_failed');

    $count = app(TenantTransaction::class)->asTenant(
        $this->sentinel,
        fn () => OutboxEvent::query()->where('type', 'TenantCreated')->count(),
    );

    expect($count)->toBe(0);
});

it('records nothing when the default locale is not supported', function () {
    $this->postJson('/v1/tenants', [
        'name' => 'Locale Fail Co',
        'default_locale' => 'fr',
        'supported_locales' => ['en', 'pt'],
    ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'default_locale_not_supported');

    $count = app(TenantTransaction::class)->asTenant(
        $this->sentinel,
        fn () => OutboxEvent::query()->where('type', 'TenantCreated')->count(),
    );

    expect($count)->toBe(0);
});

it('leaves no outbox row when the producing transaction rolls back after recording', function () {
    try {
        app(TenantTransaction::class)->asPlatform(function (): void {
            app(CreateTenant::class)(CreateTenantData::from([
                'name' => 'Rollback Tenant',
                'default_locale' => 'en',
                'supported_locales' => ['en'],
            ]));
            throw new RuntimeException('force rollback after TenantCreated record');
        });
    } catch (RuntimeException) {
        // expected
    }

    $count = app(TenantTransaction::class)->asTenant(
        $this->sentinel,
        fn () => OutboxEvent::query()->where('type', 'TenantCreated')->count(),
    );

    expect($count)->toBe(0);

    $tenants = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->where('name', 'Rollback Tenant')->count(),
    );

    expect($tenants)->toBe(0);
});
