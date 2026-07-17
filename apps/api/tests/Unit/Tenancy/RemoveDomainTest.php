<?php

use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Actions\RemoveDomain;
use App\Tenancy\Exceptions\TenantDomainIsPrimaryException;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
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

it('deletes a non-primary domain', function () {
    app(TenantTransaction::class)->asPlatform(fn () => app(RemoveDomain::class)($this->secondary));

    $remaining = app(TenantTransaction::class)->asPlatform(
        fn () => TenantDomain::query()->where('tenant_id', $this->tenant->id)->pluck('id'),
    );

    expect($remaining->all())->toBe([$this->primary->id]);
});

it('refuses to delete a primary domain', function () {
    expect(fn () => app(TenantTransaction::class)->asPlatform(fn () => app(RemoveDomain::class)($this->primary)))
        ->toThrow(TenantDomainIsPrimaryException::class);

    $fresh = app(TenantTransaction::class)->asPlatform(fn () => TenantDomain::query()->find($this->primary->id));

    expect($fresh)->not->toBeNull()
        ->and($fresh->is_primary)->toBeTrue();
});
