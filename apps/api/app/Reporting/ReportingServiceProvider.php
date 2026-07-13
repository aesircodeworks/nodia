<?php

namespace App\Reporting;

use App\Reporting\Jobs\ProjectDailySales;
use App\Reporting\Jobs\ProjectEventFinance;
use App\Support\Outbox\SubscriberRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The Reporting bounded context's own service provider (system-design
 * 3.2, CLAUDE.md: every context ships its own provider at the context
 * root; stage-11 plan, task breakdown item 3). Mounts the admin route
 * group only: Reporting has no storefront surface, every endpoint in
 * this stage is a capability-gated admin read or export (stage-11 plan,
 * Endpoints: "All endpoints are admin endpoints"). The route file stays
 * empty until tasks 6, 9, 12, and 16 land the dashboard and export
 * endpoints, mirroring EventCatalogServiceProvider's own
 * scaffolding-first precedent.
 *
 * Reporting produces no domain events (stage-11 plan, Domain events
 * "Produced": "None. Reporting is a pure consumer"), so unlike every
 * other context's provider this one registers no EventTypeRegistry
 * entries; TicketIssued and TicketRefunded are already registered by
 * App\Orders\OrdersServiceProvider, PaymentConfirmed and RefundCompleted
 * by App\Payments\PaymentsServiceProvider. ProjectDailySales (task 5)
 * and ProjectEventFinance (task 8) are the outbox subscribers this
 * provider registers.
 */
class ReportingServiceProvider extends ServiceProvider
{
    public function boot(SubscriberRegistry $subscribers): void
    {
        $subscribers->register(
            ProjectDailySales::NAME,
            ['TicketIssued', 'TicketRefunded'],
            $this->app->make(ProjectDailySales::class),
        );

        $subscribers->register(
            ProjectEventFinance::NAME,
            ['PaymentConfirmed', 'RefundCompleted'],
            $this->app->make(ProjectEventFinance::class),
        );

        Route::middleware('tenancy.admin')
            ->prefix('v1')
            ->group(__DIR__.'/Http/routes/admin.php');
    }
}
