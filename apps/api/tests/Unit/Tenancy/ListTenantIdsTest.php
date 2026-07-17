<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Actions\ListTenantIds;
use App\Tenancy\Models\Tenant;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-05c plan, task breakdown item 10: the cross-context read
 * App\EventCatalog\Support\Search\SearchIndexRebuilder uses to iterate
 * every tenant, mirroring App\Tenancy\Actions\ResolveTenantLocaleSettings's
 * own precedent for a Tenancy-owned read another context needs.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete(),
    );
});

it('lists every tenant id, including the platform tenant', function (): void {
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $ids = app(TenantTransaction::class)->asPlatform(fn () => app(ListTenantIds::class)());

    expect($ids)->toContain($tenantId)
        ->and($ids)->toContain(config()->string('tenancy.platform_tenant_id'));
});
