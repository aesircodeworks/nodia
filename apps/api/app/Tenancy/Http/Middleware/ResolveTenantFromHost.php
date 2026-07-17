<?php

namespace App\Tenancy\Http\Middleware;

use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Actions\ResolveDomain;
use App\Tenancy\Exceptions\UnknownHostException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The storefront population's tenant resolution (system-design 4.1): the
 * Host header is looked up in tenant_domains under the narrow
 * nodia_resolver posture, then the whole request runs inside a
 * transaction under SET LOCAL ROLE nodia_app with app.tenant_id set to
 * the owning tenant.
 */
class ResolveTenantFromHost
{
    use TransactsRequests;

    public function __construct(
        private readonly TenantTransaction $transaction,
        private readonly ResolveDomain $resolveDomain,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->headers->get('Host') ?? '';

        $tenantId = $this->resolveDomain->tenantIdFor($host);

        if ($tenantId === null) {
            throw UnknownHostException::forHost(ResolveDomain::normalizeHost($host));
        }

        return $this->transactRequest(
            fn (Closure $handler): Response => $this->transaction->asTenant($tenantId, $handler),
            $request,
            $next,
        );
    }
}
