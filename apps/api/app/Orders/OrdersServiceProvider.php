<?php

namespace App\Orders;

use App\Orders\Jobs\CancelOrderOnHoldExpired;
use App\Orders\Jobs\GenerateTicketPdf;
use App\Orders\Jobs\HandlePaymentConfirmed;
use App\Orders\Jobs\HandlePaymentExpired;
use App\Orders\Jobs\HandlePaymentFailed;
use App\Orders\Jobs\SendOrderConfirmation;
use App\Orders\Support\DerivedTicketSigningKeyProvider;
use App\Orders\Support\DompdfTicketPdfRenderer;
use App\Orders\Support\TicketPdfRenderer;
use App\Orders\Support\TicketSigningKeyProvider;
use App\Support\Outbox\EventTypeRegistry;
use App\Support\Outbox\SubscriberRegistry;
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
    public function register(): void
    {
        $this->app->bind(TicketSigningKeyProvider::class, DerivedTicketSigningKeyProvider::class);
        $this->app->bind(TicketPdfRenderer::class, DompdfTicketPdfRenderer::class);
    }

    public function boot(EventTypeRegistry $registry, SubscriberRegistry $subscribers): void
    {
        $registry->register('OrderCreated');
        $registry->register('TicketIssued');
        $registry->register('TicketRefunded');

        $subscribers->register(
            CancelOrderOnHoldExpired::NAME,
            ['HoldExpired'],
            $this->app->make(CancelOrderOnHoldExpired::class),
        );

        $subscribers->register(
            HandlePaymentConfirmed::NAME,
            ['PaymentConfirmed'],
            $this->app->make(HandlePaymentConfirmed::class),
        );

        $subscribers->register(
            HandlePaymentFailed::NAME,
            ['PaymentFailed'],
            $this->app->make(HandlePaymentFailed::class),
        );

        $subscribers->register(
            HandlePaymentExpired::NAME,
            ['PaymentExpired'],
            $this->app->make(HandlePaymentExpired::class),
        );

        $subscribers->register(
            SendOrderConfirmation::NAME,
            ['TicketIssued'],
            $this->app->make(SendOrderConfirmation::class),
        );

        $subscribers->register(
            GenerateTicketPdf::NAME,
            ['TicketIssued'],
            $this->app->make(GenerateTicketPdf::class),
        );

        Route::middleware('tenancy.storefront')
            ->prefix('v1')
            ->group(__DIR__.'/Http/routes/storefront.php');

        Route::middleware('tenancy.admin')
            ->prefix('v1')
            ->group(__DIR__.'/Http/routes/admin.php');
    }
}
