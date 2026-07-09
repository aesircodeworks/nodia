<?php

namespace App\Tenancy;

use App\Tenancy\Http\Middleware\PlatformAuthPlaceholder;
use App\Tenancy\Http\Middleware\PlatformRequestTransaction;
use App\Tenancy\Http\Middleware\ResolveTenantFromHeader;
use App\Tenancy\Http\Middleware\ResolveTenantFromHost;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the three request populations of system-design 4.1 as middleware
 * groups: the platform admin group (platform posture, sentinel tenant,
 * auth behind the rebindable auth.platform alias), the tenant admin group
 * (X-Tenant-Id resolution, nodia_app posture), and the storefront group
 * (Host resolution, nodia_app posture). The admin and storefront groups
 * carry no routes; other contexts attach their routes through the group
 * names, never by importing this context's middleware.
 */
class TenancyServiceProvider extends ServiceProvider
{
    public function boot(Router $router): void
    {
        $router->aliasMiddleware('auth.platform', PlatformAuthPlaceholder::class);

        $router->middlewareGroup('tenancy.platform', [
            'auth.platform',
            PlatformRequestTransaction::class,
        ]);

        $router->middlewareGroup('tenancy.admin', [ResolveTenantFromHeader::class]);
        $router->middlewareGroup('tenancy.storefront', [ResolveTenantFromHost::class]);

        Route::middleware('tenancy.platform')
            ->prefix('v1')
            ->group(__DIR__.'/Http/routes/platform.php');

        Route::prefix('v1')->group(__DIR__.'/Http/routes/internal.php');
    }
}
