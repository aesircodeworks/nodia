<?php

namespace App\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Slot for the storefront population's tenant resolution: look the Host
 * header up in tenant_domains and run the request under the nodia_app
 * posture for the owning tenant. The body fails loudly until the
 * resolution lands, so no storefront route can ever execute without a
 * resolved tenant context; the tenancy.storefront group carries no routes
 * until then.
 */
class ResolveTenantFromHost
{
    public function handle(Request $request, Closure $next): Response
    {
        throw new LogicException('Storefront tenant resolution is not implemented; the tenancy.storefront group cannot serve routes yet.');
    }
}
