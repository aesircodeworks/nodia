<?php

namespace App\Tenancy\Http\Controllers;

use App\Tenancy\Actions\ConfigureGateways;
use App\Tenancy\Actions\CreateTenant;
use App\Tenancy\Actions\UpdateBranding;
use App\Tenancy\Data\CreateTenantData;
use App\Tenancy\Data\TenantData;
use App\Tenancy\Data\UpdateTenantData;
use App\Tenancy\Exceptions\TenantNotFoundException;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\PaginatedDataCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class TenantController
{
    public function store(CreateTenantData $data, CreateTenant $createTenant): JsonResponse
    {
        return response()->json($createTenant($data), 201);
    }

    /**
     * @return PaginatedDataCollection<int, TenantData>
     */
    public function index(Request $request): PaginatedDataCollection
    {
        $tenants = QueryBuilder::for(Tenant::class)
            ->allowedFilters(AllowedFilter::partial('name'))
            ->allowedSorts('name', 'created_at')
            ->defaultSort('-created_at')
            ->paginate()
            ->appends($request->query());

        return TenantData::collect($tenants, PaginatedDataCollection::class);
    }

    public function show(string $tenant): TenantData
    {
        return TenantData::fromModel($this->tenantOrFail($tenant));
    }

    public function update(
        string $tenant,
        UpdateTenantData $data,
        UpdateBranding $updateBranding,
        ConfigureGateways $configureGateways,
    ): TenantData {
        $model = $this->tenantOrFail($tenant);

        $result = $updateBranding($model, $data);

        if (! $data->enabledGateways instanceof Optional) {
            $result = $configureGateways($model, $data->enabledGateways);
        }

        return $result;
    }

    private function tenantOrFail(string $tenantId): Tenant
    {
        return Tenant::query()->find($tenantId) ?? throw TenantNotFoundException::forId($tenantId);
    }
}
