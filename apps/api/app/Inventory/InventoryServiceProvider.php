<?php

namespace App\Inventory;

use App\Support\Outbox\EventTypeRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The Inventory bounded context's own service provider (system-design
 * 3.2, CLAUDE.md: every context ships its own provider at the context
 * root). Stage-06 plan, task breakdown items 4-6: mounts the storefront
 * hold routes and registers HoldCreated, HoldReleased, and HoldExpired
 * (event-conventions, stage-04 precedent: a type is registered in the
 * same task that ships its first producer). Inventory ships no outbox
 * consumers in this stage (stage-06 plan, Domain events: "Consumed:
 * none"), so no SubscriberRegistry wiring is needed here yet.
 */
class InventoryServiceProvider extends ServiceProvider
{
    public function boot(EventTypeRegistry $registry): void
    {
        $registry->register('HoldCreated');
        $registry->register('HoldReleased');
        $registry->register('HoldExpired');

        Route::middleware('tenancy.storefront')
            ->prefix('v1')
            ->group(__DIR__.'/Http/routes/storefront.php');
    }
}
