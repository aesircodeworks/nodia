<?php

namespace App\Tenancy\Http\Middleware;

use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Exceptions\InvalidTenantHeaderException;
use App\Tenancy\Exceptions\MissingTenantHeaderException;
use App\Tenancy\Exceptions\TenantAccessDeniedException;
use App\Tenancy\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin population's tenant resolution (system-design 4.1): the
 * X-Tenant-Id header names the acting tenant and the whole request runs
 * inside a transaction under SET LOCAL ROLE nodia_app with app.tenant_id
 * set to it. This stage validates presence, UUID shape, and tenant
 * existence only; Stage 3 adds the membership check behind the same 403,
 * so activating it never changes the contract. Existence is answered by
 * RLS itself: under the tenant's own posture the tenants isolation policy
 * exposes exactly the row whose id matches app.tenant_id, so a missing
 * row means the tenant does not exist.
 */
class ResolveTenantFromHeader
{
    use TransactsRequests;

    public function __construct(private readonly TenantTransaction $transaction) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->headers->get('X-Tenant-Id');

        if ($header === null || trim($header) === '') {
            throw MissingTenantHeaderException::make();
        }

        if (! Str::isUuid($header)) {
            throw InvalidTenantHeaderException::make();
        }

        return $this->transactRequest(
            fn (Closure $handler): Response => $this->transaction->asTenant($header, function () use ($handler, $header): Response {
                if (! Tenant::query()->whereKey($header)->exists()) {
                    throw TenantAccessDeniedException::forTenant($header);
                }

                return $handler();
            }),
            $request,
            $next,
        );
    }
}
