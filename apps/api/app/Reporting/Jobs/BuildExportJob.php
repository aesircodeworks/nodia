<?php

namespace App\Reporting\Jobs;

use App\Reporting\Actions\BuildExport;
use App\Reporting\Models\Export;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dispatched once per export request (stage-11 plan, Endpoints: "POST
 * /v1/exports" returns 202; task 16 dispatches this job). Bootstrap
 * mirrors App\Support\Outbox\Jobs\ProcessOutboxDelivery: the tenant_id
 * is read under the cross-tenant platform role (SELECT only, exports'
 * own platform-read RLS policy already grants this) because the job
 * cannot set app.tenant_id before it knows which tenant the export
 * belongs to; every write happens afterward, inside that tenant's own
 * transaction.
 *
 * A vanished export (deleted between dispatch and this job running,
 * which nothing in this stage does today but costs nothing to guard)
 * exits cleanly rather than throwing, the same defensive posture every
 * other reporting consumer in this codebase already takes on a resolved
 * cross-context fact that turns out to be absent.
 */
final class BuildExportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $exportId) {}

    public function handle(TenantTransaction $transactions, BuildExport $build): void
    {
        $tenantId = $transactions->asPlatform(
            fn (): ?string => Export::query()->whereKey($this->exportId)->value('tenant_id'),
        );

        if ($tenantId === null) {
            return;
        }

        $transactions->asTenant($tenantId, fn () => $build($this->exportId));
    }
}
