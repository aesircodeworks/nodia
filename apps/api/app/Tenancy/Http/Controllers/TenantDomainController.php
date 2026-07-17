<?php

namespace App\Tenancy\Http\Controllers;

use App\Tenancy\Actions\MakeDomainPrimary;
use App\Tenancy\Actions\RegisterDomain;
use App\Tenancy\Actions\RemoveDomain;
use App\Tenancy\Data\RegisterTenantDomainData;
use App\Tenancy\Data\TenantDomainData;
use App\Tenancy\Data\UpdateTenantDomainData;
use App\Tenancy\Exceptions\TenantDomainNotFoundException;
use App\Tenancy\Exceptions\TenantNotFoundException;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\PaginatedDataCollection;

class TenantDomainController
{
    public function store(string $tenant, RegisterTenantDomainData $data, RegisterDomain $registerDomain): JsonResponse
    {
        return response()->json($registerDomain($this->tenantOrFail($tenant), $data), 201);
    }

    /**
     * @return PaginatedDataCollection<int, TenantDomainData>
     */
    public function index(Request $request, string $tenant): PaginatedDataCollection
    {
        $this->tenantOrFail($tenant);

        $domains = TenantDomain::query()
            ->where('tenant_id', $tenant)
            ->orderBy('domain')
            ->paginate()
            ->appends($request->query());

        return TenantDomainData::collect($domains, PaginatedDataCollection::class);
    }

    public function update(
        string $tenantDomain,
        UpdateTenantDomainData $data,
        MakeDomainPrimary $makeDomainPrimary,
    ): TenantDomainData {
        $model = $this->domainOrFail($tenantDomain);

        if ($data->isPrimary instanceof Optional) {
            return TenantDomainData::fromModel($model);
        }

        return $makeDomainPrimary($model);
    }

    public function destroy(string $tenantDomain, RemoveDomain $removeDomain): Response
    {
        $removeDomain($this->domainOrFail($tenantDomain));

        return response()->noContent();
    }

    private function tenantOrFail(string $tenantId): Tenant
    {
        return Tenant::query()->find($tenantId) ?? throw TenantNotFoundException::forId($tenantId);
    }

    private function domainOrFail(string $tenantDomainId): TenantDomain
    {
        return TenantDomain::query()->find($tenantDomainId)
            ?? throw TenantDomainNotFoundException::forId($tenantDomainId);
    }
}
