<?php

namespace App\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Slot for the admin population's tenant resolution: validate X-Tenant-Id
 * and run the request under the nodia_app posture for that tenant. The
 * body fails loudly until the resolution lands, so no admin route can
 * ever execute without a resolved tenant context; the tenancy.admin group
 * carries no routes until then.
 */
class ResolveTenantFromHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        throw new LogicException('Admin tenant resolution is not implemented; the tenancy.admin group cannot serve routes yet.');
    }
}
