<?php

namespace App\Tenancy\Http\Middleware;

use App\Support\Tenancy\PlatformRoleAudit;
use App\Support\Tenancy\TenantTransaction;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The platform posture of system-design 4.1 and 4.3: the whole request
 * runs inside a transaction under SET LOCAL ROLE nodia_platform with
 * app.tenant_id set to the sentinel platform tenant. Assuming the role
 * is what triggers the audit seam, so the entry is recorded exactly when
 * the posture exists, auth denials excluded.
 */
class PlatformRequestTransaction
{
    use TransactsRequests;

    public function __construct(
        private readonly TenantTransaction $transaction,
        private readonly PlatformRoleAudit $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $this->transactRequest(
            fn (Closure $handler): Response => $this->transaction->asPlatform(function () use ($request, $handler): Response {
                $this->audit->recordRequest($request);

                return $handler();
            }),
            $request,
            $next,
        );
    }
}
