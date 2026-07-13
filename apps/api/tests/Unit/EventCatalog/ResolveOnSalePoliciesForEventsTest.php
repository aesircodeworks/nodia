<?php

use App\EventCatalog\Actions\ResolveOnSalePoliciesForEvents;
use App\EventCatalog\Data\OnSalePolicyData;
use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-10 plan, task breakdown item 8: the batched, cross-tenant seam
 * App\Inventory\Actions\RunGatekeeperTick calls under the platform role
 * (App\Support\Tenancy\TenantTransaction::asPlatform()) to read
 * on_sale_policy for every event named by App\Inventory\Support\
 * OnSaleQueue::activeMembers(), regardless of which tenant each belongs
 * to.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    foreach ([$this->tenantIdA ?? null, $this->tenantIdB ?? null] as $tenantId) {
        if ($tenantId === null) {
            continue;
        }

        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            Event::query()->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function (): void {
        if (isset($this->tenantIdA)) {
            Tenant::query()->whereKey($this->tenantIdA)->delete();
        }
        if (isset($this->tenantIdB)) {
            Tenant::query()->whereKey($this->tenantIdB)->delete();
        }
    });
});

it('resolves on_sale_policy for every requested event id in one query, across tenants', function (): void {
    $this->tenantIdA = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $this->tenantIdB = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $eventA = app(TenantTransaction::class)->asTenant($this->tenantIdA, fn () => Event::factory()->create([
        'tenant_id' => $this->tenantIdA,
        'status' => EventStatus::Published,
        'on_sale_policy' => new OnSalePolicyData(highDemand: true, admissionRatePerMinute: 5),
    ]));

    $eventB = app(TenantTransaction::class)->asTenant($this->tenantIdB, fn () => Event::factory()->create([
        'tenant_id' => $this->tenantIdB,
        'status' => EventStatus::Published,
        'on_sale_policy' => new OnSalePolicyData(highDemand: true, admissionRatePerMinute: 10),
    ]));

    $policies = app(TenantTransaction::class)->asPlatform(
        fn () => (new ResolveOnSalePoliciesForEvents)([$eventA->id, $eventB->id]),
    );

    expect($policies[$eventA->id])->toBeInstanceOf(OnSalePolicyData::class)
        ->and($policies[$eventA->id]->admissionRatePerMinute)->toBe(5)
        ->and($policies[$eventB->id]->admissionRatePerMinute)->toBe(10);
});

it('omits ids that do not exist', function (): void {
    $policies = app(TenantTransaction::class)->asPlatform(
        fn () => (new ResolveOnSalePoliciesForEvents)([Str::uuid7()->toString()]),
    );

    expect($policies)->toBe([]);
});

it('returns an empty array for an empty id list', function (): void {
    expect((new ResolveOnSalePoliciesForEvents)([]))->toBe([]);
});
