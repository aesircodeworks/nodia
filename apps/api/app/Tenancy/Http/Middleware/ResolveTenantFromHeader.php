<?php

namespace App\Tenancy\Http\Middleware;

use App\Identity\Actions\ResolveTenantAccess;
use App\Identity\Enums\MembershipAccessOutcome;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Exceptions\InvalidTenantHeaderException;
use App\Tenancy\Exceptions\MissingTenantHeaderException;
use App\Tenancy\Exceptions\TenantAccessDeniedException;
use App\Tenancy\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin population's tenant resolution (system-design 4.1): the
 * X-Tenant-Id header names the acting tenant and the whole request runs
 * inside a transaction under SET LOCAL ROLE nodia_app with app.tenant_id
 * set to it. Presence, UUID shape, and tenant existence are validated
 * first (unchanged from Stage 2); Stage 3 activates the membership check
 * behind the same 403 tenant_access_denied, so the contract never changed
 * shape, only what backs it. The 'auth:staff' middleware ahead of this one
 * in the tenancy.admin group (TenancyServiceProvider) guarantees an
 * authenticated caller by the time this class runs, so the caller's id is
 * always available before the tenant transaction opens.
 *
 * Membership validation runs through Identity's ResolveTenantAccess Action
 * rather than querying the memberships table here directly: contexts never
 * touch another context's tables, only its Actions (CLAUDE.md, tests/
 * Architecture/ContextBoundariesTest). The lookup itself must be the first
 * and only business query to run inside the tenant transaction before a
 * decision is reached (stage-03 plan, Risks: "Membership validation
 * ordering"), because an attacker-supplied tenant id briefly scopes SET
 * LOCAL app.tenant_id to whatever the header names before that decision
 * denies it; the transaction below runs exactly the tenant-existence check
 * and the membership lookup, in that order, before ever invoking $handler.
 */
class ResolveTenantFromHeader
{
    use TransactsRequests;

    public function __construct(
        private readonly TenantTransaction $transaction,
        private readonly ResolveTenantAccess $access,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->headers->get('X-Tenant-Id');

        if ($header === null || trim($header) === '') {
            throw MissingTenantHeaderException::make();
        }

        if (! Str::isUuid($header)) {
            throw InvalidTenantHeaderException::make();
        }

        $userId = $this->authenticatedUserId($request);

        return $this->transactRequest(
            fn (Closure $handler): Response => $this->transaction->asTenant($header, function () use ($handler, $header, $userId): Response {
                if (! Tenant::query()->whereKey($header)->exists()) {
                    throw TenantAccessDeniedException::forTenant($header);
                }

                $outcome = $this->access->forUser($userId, $header);

                if ($outcome === MembershipAccessOutcome::Denied) {
                    throw TenantAccessDeniedException::forTenant($header);
                }

                if ($outcome === MembershipAccessOutcome::PlatformMember) {
                    $this->transaction->elevateToPlatformRole();
                }

                return $handler();
            }, $userId),
            $request,
            $next,
        );
    }

    /**
     * auth:staff already rejected the request with 401 before this
     * middleware ever runs when no bearer resolves; this is a defensive
     * assertion against a future route wiring mistake, not the primary
     * enforcement.
     */
    private function authenticatedUserId(Request $request): string
    {
        $user = $request->user('staff');

        if ($user === null) {
            throw new LogicException('ResolveTenantFromHeader requires an authenticated staff user; ensure auth:staff runs first in the tenancy.admin group.');
        }

        return $user->getAuthIdentifier();
    }
}
