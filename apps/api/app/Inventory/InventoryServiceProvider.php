<?php

namespace App\Inventory;

use App\Inventory\Support\ChallengeVerifier;
use App\Inventory\Support\NoOpChallengeVerifier;
use App\Inventory\Support\RateLimiterKeys;
use App\Support\Outbox\EventTypeRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The Inventory bounded context's own service provider (system-design
 * 3.2, CLAUDE.md: every context ships its own provider at the context
 * root). Stage-06 plan, task breakdown items 4-7: mounts the storefront
 * hold and availability routes plus the tenant admin ticket-type
 * inventory route, and registers HoldCreated, HoldReleased, and
 * HoldExpired (event-conventions, stage-04 precedent: a type is
 * registered in the same task that ships its first producer). Inventory
 * ships no outbox consumers in this stage (stage-06 plan, Domain events:
 * "Consumed: none"), so no SubscriberRegistry wiring is needed here yet.
 *
 * Stage-10 plan, Endpoints "Rate limiting tiers": named limiters, one
 * per tier, config-driven from config/onsale.php so tuning never needs a
 * deploy. `queue_entry` and `queue_poll` are registered ahead of the
 * routes that will use them (task breakdown item 11 depends on this
 * task landing first); they attach to the waiting-room routes once
 * those land.
 */
class InventoryServiceProvider extends ServiceProvider
{
    /**
     * Stage-10 plan, Scope "A challenge hook at queue entry": the
     * default App\Inventory\Support\ChallengeVerifier binding until a
     * real provider lands behind an ADR. Tests swap this binding
     * explicitly (App\Inventory\Support\FakeChallengeVerifier) to
     * exercise the challenge_failed path.
     */
    public function register(): void
    {
        $this->app->bind(ChallengeVerifier::class, NoOpChallengeVerifier::class);
    }

    public function boot(EventTypeRegistry $registry): void
    {
        $registry->register('HoldCreated');
        $registry->register('HoldReleased');
        $registry->register('HoldExpired');

        $this->registerRateLimiters();

        Route::middleware('tenancy.admin')
            ->prefix('v1')
            ->group(__DIR__.'/Http/routes/admin.php');

        Route::middleware('tenancy.storefront')
            ->prefix('v1')
            ->group(__DIR__.'/Http/routes/storefront.php');
    }

    private function registerRateLimiters(): void
    {
        RateLimiter::for('browse', function (Request $request) {
            $tier = config('onsale.rate_limits.browse');

            return new Limit(RateLimiterKeys::ip($request), $tier['max_attempts'], $tier['decay_seconds']);
        });

        RateLimiter::for('queue_entry', function (Request $request) {
            $tier = config('onsale.rate_limits.queue_entry');

            return new Limit(RateLimiterKeys::ip($request), $tier['max_attempts'], $tier['decay_seconds']);
        });

        RateLimiter::for('queue_poll', function (Request $request) {
            $tier = config('onsale.rate_limits.queue_poll');

            return new Limit(RateLimiterKeys::ip($request), $tier['max_attempts'], $tier['decay_seconds']);
        });

        RateLimiter::for('hold_creation', function (Request $request) {
            $ipTier = config('onsale.rate_limits.hold_creation.ip');

            $limits = [new Limit(RateLimiterKeys::ip($request), $ipTier['max_attempts'], $ipTier['decay_seconds'])];

            if ($customerId = RateLimiterKeys::customer($request)) {
                $customerTier = config('onsale.rate_limits.hold_creation.customer');
                $limits[] = new Limit($customerId, $customerTier['max_attempts'], $customerTier['decay_seconds']);
            }

            return $limits;
        });
    }
}
