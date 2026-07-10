<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Actions\RegisterDomain;
use App\Tenancy\Data\RegisterTenantDomainData;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PlatformStaff;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-04 plan, Slice 6 (task-14): DomainVerified producer on RegisterDomain
 * (settled Stage 2 trigger: registration). Successful domain registration
 * over HTTP persists exactly one outbox row with the owning tenant in the
 * envelope; failure paths record nothing.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->sentinel = config()->string('tenancy.platform_tenant_id');

    $this->tenant = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(),
    );

    $this->withHeaders(['Authorization' => 'Bearer '.PlatformStaff::token()]);
});

afterEach(function (): void {
    $sentinel = $this->sentinel;

    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ([...$tenantIds, $sentinel] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
            DB::table('memberships')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });

    User::query()->delete();
});

it('persists exactly one DomainVerified outbox row on a successful registration over HTTP', function () {
    $correlationId = 'domain-verified-correlation-'.Str::uuid7()->toString();

    $response = $this->withHeaders(['X-Correlation-Id' => $correlationId])
        ->postJson('/v1/tenants/'.$this->tenant->id.'/domains', [
            'domain' => 'outbox.acme.com',
        ]);

    $response->assertCreated();

    $domainId = $response->json('id');

    $rows = app(TenantTransaction::class)->asTenant(
        $this->tenant->id,
        fn () => OutboxEvent::query()->where('type', 'DomainVerified')->get(),
    );

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    expect($row->type)->toBe('DomainVerified')
        ->and($row->aggregate_type)->toBe('tenant_domain')
        ->and($row->aggregate_id)->toBe($domainId)
        ->and($row->tenant_id)->toBe($this->tenant->id)
        ->and($row->correlation_id)->toBe($correlationId)
        ->and($row->payload)->toMatchArray([
            'tenant_domain_id' => $domainId,
            'tenant_id' => $this->tenant->id,
            'domain' => 'outbox.acme.com',
        ])
        ->and(array_keys($row->payload))->toEqualCanonicalizing([
            'tenant_domain_id',
            'tenant_id',
            'domain',
        ]);
});

it('records nothing when the registration request fails validation', function () {
    $this->postJson('/v1/tenants/'.$this->tenant->id.'/domains', [
        'domain' => 'not a hostname',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'request.validation_failed');

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenant->id,
        fn () => OutboxEvent::query()->where('type', 'DomainVerified')->count(),
    );

    expect($count)->toBe(0);
});

it('records nothing when the domain is already registered', function () {
    app(TenantTransaction::class)->asPlatform(function (): void {
        TenantDomain::factory()->create([
            'tenant_id' => $this->tenant->id,
            'domain' => 'taken.acme.com',
        ]);
    });

    $this->postJson('/v1/tenants/'.$this->tenant->id.'/domains', [
        'domain' => 'taken.acme.com',
    ])
        ->assertConflict()
        ->assertJsonPath('code', 'domain_already_registered');

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenant->id,
        fn () => OutboxEvent::query()->where('type', 'DomainVerified')->count(),
    );

    expect($count)->toBe(0);
});

it('records nothing when registering a second primary domain', function () {
    app(TenantTransaction::class)->asPlatform(function (): void {
        TenantDomain::factory()->create([
            'tenant_id' => $this->tenant->id,
            'domain' => 'first.acme.com',
            'is_primary' => true,
        ]);
    });

    $this->postJson('/v1/tenants/'.$this->tenant->id.'/domains', [
        'domain' => 'second.acme.com',
        'is_primary' => true,
    ])
        ->assertConflict()
        ->assertJsonPath('code', 'tenant_domain_is_primary');

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenant->id,
        fn () => OutboxEvent::query()->where('type', 'DomainVerified')->count(),
    );

    expect($count)->toBe(0);
});

it('leaves no outbox row when the producing transaction rolls back after recording', function () {
    $tenant = $this->tenant;

    try {
        app(TenantTransaction::class)->asPlatform(function () use ($tenant): void {
            app(RegisterDomain::class)(
                $tenant,
                RegisterTenantDomainData::from(['domain' => 'rollback.acme.com']),
            );
            throw new RuntimeException('force rollback after DomainVerified record');
        });
    } catch (RuntimeException) {
        // expected
    }

    $count = app(TenantTransaction::class)->asTenant(
        $tenant->id,
        fn () => OutboxEvent::query()->where('type', 'DomainVerified')->count(),
    );

    expect($count)->toBe(0);

    $domains = app(TenantTransaction::class)->asPlatform(
        fn () => TenantDomain::query()->where('domain', 'rollback.acme.com')->count(),
    );

    expect($domains)->toBe(0);
});
