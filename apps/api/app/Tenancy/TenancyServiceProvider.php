<?php

namespace App\Tenancy;

use App\Http\Middleware\EnforceMfaCompliance;
use App\Http\Middleware\RequireCapability;
use App\Identity\Capability;
use App\Tenancy\Http\Middleware\PlatformRequestTransaction;
use App\Tenancy\Http\Middleware\ResolveTenantFromHeader;
use App\Tenancy\Http\Middleware\ResolveTenantFromHost;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the three request populations of system-design 4.1 as middleware
 * groups: the platform admin group (Passport staff bearer, platform
 * posture under the sentinel tenant, tenants.manage required), the tenant
 * admin group (Passport staff bearer, X-Tenant-Id resolution against the
 * caller's memberships, nodia_app or nodia_platform posture), and the
 * storefront group (Host resolution, nodia_app posture, no staff identity
 * involved). The admin and storefront groups carry no routes; other
 * contexts attach their routes through the group names, never by
 * importing this context's middleware. 'auth:staff' runs first in every
 * admin-facing group so a caller's id is always available before the
 * tenant transaction that follows opens; a missing or invalid bearer is
 * rejected with 401 before anything else runs.
 *
 * Stage 3 task breakdown item 7 rebinds the platform group's Stage 2
 * placeholder ('auth.platform', a pass-through denying everything outside
 * testing and local) to real Passport bearer authentication plus
 * RequireCapability, closing that stage's deferral: tenant and domain CRUD
 * now require tenants.manage, evaluated by Identity's CapabilityGate
 * against the acting membership PlatformRequestTransaction's posture
 * resolves under (the sentinel platform tenant, nodia_platform role).
 * RequireCapability runs after PlatformRequestTransaction so TenantContext
 * already carries that tenant when the capability check runs.
 *
 * Stage 3 task breakdown item 11 inserts App\Http\Middleware\
 * EnforceMfaCompliance into both admin-facing groups, after the
 * middleware that opens the tenant transaction and before
 * RequireCapability on the platform group: a platform-scope or
 * financially privileged caller who has not confirmed MFA is denied
 * mfa_enforcement_required before any capability check ever runs, so the
 * denial fires uniformly across every route in both groups (including
 * capability-free reads like GET /v1/roles), never conditioned on which
 * capability a particular route happens to require.
 */
class TenancyServiceProvider extends ServiceProvider
{
    public function boot(Router $router): void
    {
        $router->middlewareGroup('tenancy.platform', [
            'auth:staff',
            PlatformRequestTransaction::class,
            EnforceMfaCompliance::class,
            RequireCapability::class.':'.Capability::TenantsManage->value,
        ]);

        $router->middlewareGroup('tenancy.admin', ['auth:staff', ResolveTenantFromHeader::class, EnforceMfaCompliance::class]);
        $router->middlewareGroup('tenancy.storefront', [ResolveTenantFromHost::class]);

        Route::middleware('tenancy.platform')
            ->prefix('v1')
            ->group(__DIR__.'/Http/routes/platform.php');

        Route::prefix('v1')->group(__DIR__.'/Http/routes/internal.php');
    }
}
