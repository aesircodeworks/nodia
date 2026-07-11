<?php

namespace App\Payments;

use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\FakeGatewayScenarios;
use App\Payments\Gateways\GatewayRegistry;
use App\Support\Outbox\EventTypeRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The Payments bounded context's own service provider (system-design 3.2,
 * CLAUDE.md: every context ships its own provider at the context root).
 * The scenario store and adapters are container-scoped, never singletons,
 * so scripted state cannot leak across Octane requests (system-design
 * 16.2).
 */
class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(FakeGatewayScenarios::class);
        $this->app->scoped(FakeGateway::class);

        $this->app->scoped(GatewayRegistry::class, fn (Application $app) => new GatewayRegistry([
            FakeGateway::IDENTIFIER => $app->make(FakeGateway::class),
        ]));
    }

    public function boot(EventTypeRegistry $registry): void
    {
        $registry->register('PaymentInitiated');
        $registry->register('PaymentConfirmed');
        $registry->register('PaymentFailed');

        Route::middleware('tenancy.storefront')
            ->prefix('v1')
            ->group(__DIR__.'/Http/routes/storefront.php');

        Route::prefix('v1')->group(__DIR__.'/Http/routes/webhooks.php');
    }
}
