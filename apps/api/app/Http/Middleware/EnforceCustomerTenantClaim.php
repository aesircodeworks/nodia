<?php

namespace App\Http\Middleware;

use App\Identity\Exceptions\TenantMismatchException;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Customer tokens carry a tenant_id claim, checked here at the
 * application layer rather than relying on RLS to hide a cross-tenant
 * customer row (App\Support\Database\Rls::grantUnscoped's own docblock
 * for the oauth tables; stage-03 plan, Customer authentication and
 * lifecycle: "a customer token used against another tenant's host is 401
 * tenant_mismatch"). customers carries RLS FORCE-enabled (task breakdown
 * item 12), so a customer token whose subject genuinely belongs to a
 * different tenant than the host resolved would make Passport's own user
 * lookup find no row visible under this request's asserted tenant,
 * rendering the generic auth.unauthenticated rather than the distinct
 * tenant_mismatch code the plan wants; this middleware reads the JWT's
 * own tenant_id claim directly, ahead of auth:customer, so that
 * distinction is possible at all.
 *
 * The claim is read without verifying the token's signature: this is a
 * UX distinction between two already-denying outcomes, never a privilege
 * escalation, because auth:customer immediately afterward fully verifies
 * signature, expiry, and revocation through Passport's own ResourceServer
 * regardless of what this middleware decides; a garbage or unparseable
 * bearer is simply passed through unmodified for that guard to reject.
 *
 * Attached to the whole tenancy.storefront group
 * (App\Tenancy\TenancyServiceProvider) rather than only the routes that
 * currently authenticate a customer, the same "every future route gets
 * it for free" reasoning App\Http\Middleware\EnforceMfaCompliance already
 * established for the admin groups; every unauthenticated storefront
 * route (token issuance, registration, claim) simply carries no bearer,
 * so this is a no-op for them.
 */
class EnforceCustomerTenantClaim
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if ($bearer === null) {
            return $next($request);
        }

        $tenantId = $this->tenantClaimFrom($bearer);

        if ($tenantId !== null && $tenantId !== $this->context->tenantId()) {
            throw TenantMismatchException::make();
        }

        return $next($request);
    }

    private function tenantClaimFrom(string $bearer): ?string
    {
        try {
            /** @var Plain $token */
            $token = (new Parser(new JoseEncoder))->parse($bearer);
        } catch (Throwable) {
            return null;
        }

        $claims = $token->claims();

        if (! $claims->has('tenant_id')) {
            return null;
        }

        /** @var mixed $tenantId */
        $tenantId = $claims->get('tenant_id');

        return is_string($tenantId) ? $tenantId : null;
    }
}
