<?php

namespace App\CheckIn;

use App\Support\Outbox\EventTypeRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The CheckIn bounded context's own service provider (system-design 3.2,
 * CLAUDE.md: every context ships its own provider at the context root),
 * first needed by the stage-09 manifest endpoint. Mounts the staff
 * check-in routes under the tenancy.admin group, mirroring
 * OrdersServiceProvider's own boot() shape. Registers this context's two
 * outbox event types (stage-09 plan, Domain events "Produced").
 */
class CheckInServiceProvider extends ServiceProvider
{
    public function boot(EventTypeRegistry $registry): void
    {
        $registry->register('TicketCheckedIn');
        $registry->register('DuplicateScanDetected');

        Route::middleware('tenancy.admin')
            ->prefix('v1')
            ->group(__DIR__.'/Http/routes/admin.php');
    }
}
