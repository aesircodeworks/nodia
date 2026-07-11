<?php

namespace App\Tenancy\Http\Controllers;

use App\Tenancy\Actions\UploadTenantMedia;
use App\Tenancy\Data\TenantMediaUploadData;
use App\Tenancy\Exceptions\TenantNotFoundException;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;

/**
 * Tenant branding logo upload (stage-05c plan, Endpoints: "Admin tenant
 * branding media"). Runs under the tenancy.platform group like every
 * other tenant mutation (TenancyServiceProvider), so tenants.manage is
 * already enforced by RequireCapability before this controller ever
 * runs; deletion is the shared top-level DELETE /v1/media/{media} in
 * App\Http\Controllers\MediaController instead, mirroring
 * App\EventCatalog\Http\Controllers\EventMediaController's own precedent.
 *
 * tenantOrFail() duplicates App\Tenancy\Http\Controllers\TenantController's
 * own private method rather than sharing it (that method is private, and
 * this endpoint deliberately renders the same tenant_not_found code the
 * rest of the tenant mutation surface already uses for an unknown id,
 * keeping one stable code per distinct error condition, api-conventions
 * "Errors": "code values are stable API contract").
 */
class TenantMediaController
{
    public function store(string $tenant, TenantMediaUploadData $data, UploadTenantMedia $uploadTenantMedia): JsonResponse
    {
        return response()->json($uploadTenantMedia($this->tenantOrFail($tenant), $data), 201);
    }

    private function tenantOrFail(string $tenantId): Tenant
    {
        return Tenant::query()->find($tenantId) ?? throw TenantNotFoundException::forId($tenantId);
    }
}
