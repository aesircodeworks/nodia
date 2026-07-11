<?php

namespace App\Orders;

use App\Support\Outbox\EventTypeRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The Orders bounded context's own service provider (system-design 3.2,
 * CLAUDE.md: every context ships its own provider at the context root).
 * Registers each event type in the same task that ships its first
 * producer (event-conventions, stage-04 precedent) and mounts the
 * storefront order routes.
 */
class OrdersServiceProvider extends ServiceProvider
{
    public function boot(EventTypeRegistry $registry): void
    {
        $registry->register('OrderCreated');

        Route::middleware('tenancy.storefront')
            ->prefix('v1')
            ->group(__DIR__.'/Http/routes/storefront.php');
    }
}
