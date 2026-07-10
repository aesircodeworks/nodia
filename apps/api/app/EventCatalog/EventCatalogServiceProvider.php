<?php

namespace App\EventCatalog;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The Event Catalog bounded context's own service provider (system-design
 * 3.2, CLAUDE.md: every context ships its own provider at the context
 * root). Stage-05a plan, task breakdown item 2: mounts this context's two
 * route groups, the tenant admin surface (staff bearer, X-Tenant-Id,
 * events.view/events.manage/events.publish per
 * App\Http\Middleware\RequireCapability, task breakdown item 1) and the
 * storefront surface (Host resolution, no auth). Both route files are
 * empty until their owning task lands (Http/routes/admin.php's own
 * docblock).
 *
 * No standalone Policy classes ship in this task, mirroring the stage-03
 * plan's own precedent for the same situation (task-06 journal,
 * "Decision: no standalone Policy class ships this task"): Venue, Event,
 * and TicketType do not exist until task breakdown items 3, 4, and 7, so
 * a Policy class attached to a model has nothing to attach to yet.
 * App\Identity\Authorization\CapabilityGate is already registered
 * globally (IdentityServiceProvider::boot()) and evaluates capability
 * plus tenant context regardless of which context's route uses it
 * (system-design 5.3, ADR 012); this context consumes that Gate directly
 * through RequireCapability on its own routes once they exist, the same
 * way Tenancy and Identity's own admin routes already do, rather than
 * introducing a second capability-resolution path.
 *
 * No event type registrations yet (event-conventions, stage-04
 * precedent: a type is registered in the same task that ships its first
 * producer, e.g. App\Tenancy\TenancyServiceProvider registering
 * TenantCreated alongside CreateTenant): CreateEvent, UpdateEvent,
 * PublishEvent, and CancelEvent do not exist until later tasks, so
 * EventCreated, EventUpdated, EventPublished, and EventCanceled are not
 * registered here despite already being reserved in the system-design
 * 9.3 registry (stage-05a plan, Domain events: "no registry change
 * needed"). Task breakdown items 5 and 9 add the registration calls
 * alongside their producing Actions.
 */
class EventCatalogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('tenancy.admin')
            ->prefix('v1')
            ->group(__DIR__.'/Http/routes/admin.php');

        Route::middleware('tenancy.storefront')
            ->prefix('v1')
            ->group(__DIR__.'/Http/routes/storefront.php');
    }
}
