<?php

namespace App\EventCatalog;

use App\EventCatalog\Jobs\RefreshSearchIndex;
use App\EventCatalog\Support\Search\EventSearcher;
use App\EventCatalog\Support\Search\PostgresEventSearcher;
use App\Support\Outbox\EventTypeRegistry;
use App\Support\Outbox\SubscriberRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * The Event Catalog bounded context's own service provider (system-design
 * 3.2, CLAUDE.md: every context ships its own provider at the context
 * root). Stage-05a plan, task breakdown item 2: mounts this context's two
 * route groups, the tenant admin surface (staff bearer, X-Tenant-Id,
 * events.view/events.manage/events.publish per
 * App\Http\Middleware\RequireCapability, task breakdown item 1) and the
 * storefront surface (Host resolution, no auth, the published read
 * endpoints of task breakdown item 10).
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
 * EventCreated and EventUpdated are registered below alongside this
 * task's CreateEvent/UpdateEvent producers (event-conventions, stage-04
 * precedent: a type is registered in the same task that ships its first
 * producer). EventPublished and EventCanceled are registered alongside
 * task breakdown item 9's PublishEvent/CancelEvent producers, the same
 * way, despite both already being reserved in the system-design 9.3
 * registry (stage-05a plan, Domain events: "no registry change needed").
 *
 * App\EventCatalog\Jobs\RefreshSearchIndex (stage-05c plan, task
 * breakdown item 7) subscribes to all four types here: production
 * consumers register from their owning context provider
 * (App\Providers\AppServiceProvider's own docblock), and this is the
 * context that owns both `events` and the `event_search_documents`
 * projection it maintains.
 *
 * App\EventCatalog\Support\Search\EventSearcher is bound here, in
 * register() rather than boot() (the standard Laravel place for
 * container bindings), keyed off config('search.driver') (stage-05c
 * plan, task breakdown item 8): postgres resolves to
 * App\EventCatalog\Support\Search\PostgresEventSearcher, the only driver
 * this stage implements; any other configured value fails loudly at
 * resolution time rather than silently falling back, since a
 * misconfigured driver has no safe default. This is the one place in the
 * codebase permitted to reference PostgresEventSearcher directly
 * (tests/Architecture/SearchSeamTest.php); every consumer, including the
 * storefront search controller a later task in this stage adds, depends
 * on the EventSearcher interface only, so swapping in a Meilisearch
 * driver later (system-design 15.3) is a change to this binding alone.
 */
class EventCatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EventSearcher::class, function (Application $app): EventSearcher {
            $driver = config()->string('search.driver');

            return match ($driver) {
                'postgres' => $app->make(PostgresEventSearcher::class),
                default => throw new InvalidArgumentException(
                    "Unsupported search.driver [{$driver}]; only postgres is implemented.",
                ),
            };
        });
    }

    public function boot(EventTypeRegistry $registry, SubscriberRegistry $subscribers): void
    {
        $registry->register('EventCreated');
        $registry->register('EventUpdated');
        $registry->register('EventPublished');
        $registry->register('EventCanceled');

        $subscribers->register(RefreshSearchIndex::NAME, [
            'EventCreated',
            'EventUpdated',
            'EventPublished',
            'EventCanceled',
        ], $this->app->make(RefreshSearchIndex::class));

        Route::middleware('tenancy.admin')
            ->prefix('v1')
            ->group(__DIR__.'/Http/routes/admin.php');

        Route::middleware('tenancy.storefront')
            ->prefix('v1')
            ->group(__DIR__.'/Http/routes/storefront.php');
    }
}
