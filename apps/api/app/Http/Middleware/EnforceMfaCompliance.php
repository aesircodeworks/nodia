<?php

namespace App\Http\Middleware;

use App\Identity\Actions\ResolveActingMembership;
use App\Identity\Exceptions\MfaEnforcementRequiredException;
use App\Identity\Support\MfaEnforcementPolicy;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MFA enforcement (stage-03 plan, MFA enforcement paragraph; system-design
 * 5.1, 14.2): when the acting membership is platform-scope, or its role's
 * capability set intersects the financially privileged set, every request
 * is denied with mfa_enforcement_required until the caller has confirmed
 * MFA. Attached to the tenancy.admin and tenancy.platform groups only
 * (App\Tenancy\TenancyServiceProvider), after the middleware that opens
 * the tenant transaction (ResolveTenantFromHeader or
 * PlatformRequestTransaction), so TenantContext already carries the
 * tenant ResolveActingMembership resolves the acting membership against.
 * Lives under App\Http\Middleware rather than either bounded context's
 * own Http layer, the same precedent RequireCapability already
 * established (task breakdown item 8 journal): both contexts' route
 * groups need it, and ContextBoundariesTest forbids a context from using
 * another context's Http classes.
 */
class EnforceMfaCompliance
{
    public function __construct(
        private readonly ResolveActingMembership $membership,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user('staff');

        if ($user === null) {
            // auth:staff always runs first in both enforced groups
            // (TenancyServiceProvider); this is a defensive fallback
            // against a future route wiring mistake, not the primary
            // enforcement, mirroring ResolveTenantFromHeader's own
            // precedent for the same situation.
            return $next($request);
        }

        $membership = $this->membership->forUser($user->getAuthIdentifier(), $this->context->tenantId());

        if ($membership === null || ! MfaEnforcementPolicy::requires($membership->scope, $membership->role->capabilities)) {
            return $next($request);
        }

        if (! $user->mfa_enabled || $user->mfa_confirmed_at === null) {
            throw MfaEnforcementRequiredException::make();
        }

        return $next($request);
    }
}
