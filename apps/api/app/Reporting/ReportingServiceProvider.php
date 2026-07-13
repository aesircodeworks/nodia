<?php

namespace App\Reporting;

use App\Reporting\Jobs\ProjectDailySales;
use App\Reporting\Jobs\ProjectEventAttendance;
use App\Reporting\Jobs\ProjectEventFinance;
use App\Reporting\Support\Export\ExportSourceRegistry;
use App\Reporting\Support\Export\Sources\OrdersExportSource;
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
 * by App\Payments\PaymentsServiceProvider, TicketCheckedIn and
 * DuplicateScanDetected by App\CheckIn\CheckInServiceProvider.
 * ProjectDailySales (task 5), ProjectEventFinance (task 8), and
 * ProjectEventAttendance (task 11) are the outbox subscribers this
 * provider registers.
 *
 * ExportSourceRegistry (task 15) is bound as a singleton here rather
 * than left to auto-resolution, so the same populated instance answers
 * every consumer: the future POST /v1/exports validator (task 16) and
 * App\Reporting\Actions\BuildExport. `orders` is the only source
 * registered by this task; `tickets` and `ledger_entries` land with task
 * 12, `check_ins` with or after task 11's attendance projector.
 */
class ReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ExportSourceRegistry::class);
    }

    public function boot(SubscriberRegistry $subscribers, ExportSourceRegistry $exportSources): void
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

        $subscribers->register(
            ProjectEventAttendance::NAME,
            ['TicketCheckedIn', 'DuplicateScanDetected'],
            $this->app->make(ProjectEventAttendance::class),
        );

        $exportSources->register($this->app->make(OrdersExportSource::class));

        Route::middleware('tenancy.admin')
            ->prefix('v1')
            ->group(__DIR__.'/Http/routes/admin.php');
    }
}
