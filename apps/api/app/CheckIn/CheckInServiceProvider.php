<?php

namespace App\CheckIn;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The CheckIn bounded context's own service provider (system-design 3.2,
 * CLAUDE.md: every context ships its own provider at the context root),
 * first needed by the stage-09 manifest endpoint. Mounts the staff
 * check-in routes under the tenancy.admin group, mirroring
 * OrdersServiceProvider's own boot() shape.
 */
class CheckInServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('tenancy.admin')
            ->prefix('v1')
            ->group(__DIR__.'/Http/routes/admin.php');
    }
}
