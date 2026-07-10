<?php

namespace App\Tenancy\Http\Middleware;

use App\Identity\Authorization\CapabilityGate;
use App\Identity\Capability;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires the caller's acting membership, resolved under whichever tenant
 * transaction already opened ahead of this middleware (Passport bearer
 * plus capability rebinding of Stage 2's placeholder platform auth alias,
 * stage-03 plan task breakdown item 7), to hold the named capability.
 * Route middleware parameter syntax carries the capability's wire value,
 * e.g. RequireCapability::class.':tenants.manage'; delegates the actual
 * resolution and denial (missing_capability, 403) to Identity's
 * CapabilityGate, the single place capability evaluation happens
 * (system-design 5.3, ADR 012), rather than duplicating any of its logic
 * here. Must run after the middleware that opens the tenant transaction
 * (PlatformRequestTransaction for the platform group) so TenantContext
 * already carries the tenant CapabilityGate resolves the membership
 * against.
 */
class RequireCapability
{
    public function __construct(private readonly CapabilityGate $gate) {}

    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $this->gate->authorize(Capability::from($capability));

        return $next($request);
    }
}
