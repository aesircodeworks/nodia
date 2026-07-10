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
 *
 * The audit write runs in its own platform transaction, committed before
 * the request handler's transaction ever opens (stage-03 task-15): an
 * activity_log row inserted inside the same transaction as the handler
 * would roll back with it, which would break the pre-existing "the audit
 * entry still records when the platform handler fails and the transaction
 * rolls back" guarantee (system-design 4.3's audit is "the role was
 * assumed," not "the handler succeeded"). Two separate, sequential
 * asPlatform() calls are two separate committed transactions, never
 * nested, so the first's row is durable regardless of what the second
 * does afterward.
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
        $this->transaction->asPlatform(fn () => $this->audit->recordRequest($request));

        return $this->transactRequest(
            fn (Closure $handler): Response => $this->transaction->asPlatform($handler),
            $request,
            $next,
        );
    }
}
