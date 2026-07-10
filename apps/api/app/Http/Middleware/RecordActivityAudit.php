<?php

namespace App\Http\Middleware;

use App\Support\Audit\ActivityLogger;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records an activity_log entry for a mutating request that actually
 * succeeded (stage-03 plan, Slice 7: "every mutating endpoint ... writes
 * an entry in the acting tenant"). Attached as route-level middleware
 * directly on the specific mutating routes that need it (Identity's
 * roles.php, memberships.php, and customer-auth.php), never on a whole
 * tenancy.admin/tenancy.storefront group: those groups also carry
 * read-only routes this audit has no reason to cover, unlike the
 * platform group's own PlatformRoleAudit, which deliberately covers every
 * request (reads included) as a distinct "the platform role was assumed"
 * signal, not a mutation signal, and continues to be recorded separately
 * by PlatformRequestTransaction. Tenant/domain CRUD (Stage 2) needs no
 * second entry from this middleware: it already runs entirely under the
 * platform group, so PlatformRoleAudit's per-request entry already
 * satisfies "an entry in the acting tenant" for it (the acting tenant
 * being the sentinel platform tenant those routes always operate under).
 *
 * Runs after the middleware that opens the tenant transaction
 * (ResolveTenantFromHeader or ResolveTenantFromHost) and, for the two
 * roles.php/memberships.php inner groups, after RequireCapability, so a
 * denied request (missing_capability, tenant_access_denied,
 * mfa_enforcement_required) never reaches $next() successfully and is
 * correctly skipped below. The entry is written from inside the same
 * ambient transaction the mutation itself ran in (no transaction of its
 * own, matching ActivityLogger's own precedent): if that transaction
 * later rolls back for an unrelated reason, the entry rolls back with it,
 * which is correct here (unlike PlatformRoleAudit's own pre-committed
 * design) because nothing was actually mutated in that case.
 *
 * ActivityLogger tags every entry with platform_scope from the ambient
 * TenantContext automatically, so a platform-scope member reaching a
 * tenant-scope admin route through ResolveTenantAccess's platform-scope
 * fallback (TenantTransaction::elevateToPlatformRole, stage-03 task-05)
 * is flagged here for free, closing that task's own deferred audit note.
 */
class RecordActivityAudit
{
    /** @var list<string> */
    private const array MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private readonly ActivityLogger $logger) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! in_array($request->getMethod(), self::MUTATING_METHODS, true) || ! $response->isSuccessful()) {
            return $response;
        }

        $causer = $request->user('staff') ?? $request->user('customer');

        $this->logger->record(
            description: sprintf('%s %s', $request->getMethod(), '/'.ltrim($request->path(), '/')),
            causer: $causer instanceof Model ? $causer : null,
            event: 'mutation',
        );

        return $response;
    }
}
