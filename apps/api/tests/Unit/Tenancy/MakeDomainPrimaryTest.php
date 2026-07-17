<?php

use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Actions\MakeDomainPrimary;
use App\Tenancy\Data\TenantDomainData;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    [$this->tenant, $this->primary, $this->secondary] = app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create();

        return [
            $tenant,
            TenantDomain::factory()->create(['tenant_id' => $tenant->id, 'is_primary' => true]),
            TenantDomain::factory()->create(['tenant_id' => $tenant->id, 'is_primary' => false]),
        ];
    });
});

afterEach(function (): void {
    app(TenantTransaction::class)->asPlatform(function (): void {
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });
});

it('demotes the current primary and promotes the target', function () {
    $result = app(TenantTransaction::class)->asPlatform(fn () => app(MakeDomainPrimary::class)($this->secondary));

    expect($result)->toBeInstanceOf(TenantDomainData::class)
        ->and($result->isPrimary)->toBeTrue();

    $primaries = app(TenantTransaction::class)->asPlatform(
        fn () => TenantDomain::query()->where('tenant_id', $this->tenant->id)->where('is_primary', true)->get(),
    );

    expect($primaries)->toHaveCount(1)
        ->and($primaries->first()->id)->toBe($this->secondary->id);
});

it('is a no-op on replay, leaving the already-primary row unwritten', function () {
    $frozen = '2026-01-01 00:00:00+00';

    app(TenantTransaction::class)->asPlatform(
        fn () => TenantDomain::query()->whereKey($this->primary->id)->update(['updated_at' => $frozen]),
    );

    $result = app(TenantTransaction::class)->asPlatform(fn () => app(MakeDomainPrimary::class)($this->primary));

    expect($result->isPrimary)->toBeTrue();

    $fresh = app(TenantTransaction::class)->asPlatform(fn () => TenantDomain::query()->find($this->primary->id));

    expect($fresh->is_primary)->toBeTrue()
        ->and($fresh->updated_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-01-01 00:00:00');

    $primaries = app(TenantTransaction::class)->asPlatform(
        fn () => TenantDomain::query()->where('tenant_id', $this->tenant->id)->where('is_primary', true)->count(),
    );

    expect($primaries)->toBe(1);
});

it('promotes when the tenant has no primary domain', function () {
    app(TenantTransaction::class)->asPlatform(
        fn () => TenantDomain::query()->whereKey($this->primary->id)->update(['is_primary' => false]),
    );

    $result = app(TenantTransaction::class)->asPlatform(fn () => app(MakeDomainPrimary::class)($this->secondary));

    expect($result->isPrimary)->toBeTrue();
});

it('rolls the demotion back when the target row no longer exists', function () {
    app(TenantTransaction::class)->asPlatform(function (): void {
        TenantDomain::query()->whereKey($this->secondary->id)->delete();

        expect(fn () => app(MakeDomainPrimary::class)($this->secondary))
            ->toThrow(ModelNotFoundException::class);
    });

    $fresh = app(TenantTransaction::class)->asPlatform(fn () => TenantDomain::query()->find($this->primary->id));

    expect($fresh->is_primary)->toBeTrue();
});
