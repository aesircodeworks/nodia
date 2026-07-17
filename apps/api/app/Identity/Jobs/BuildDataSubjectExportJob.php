<?php

namespace App\Identity\Jobs;

use App\Identity\Actions\BuildDataSubjectExport;
use App\Identity\Models\DataSubjectRequest;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dispatched once per export data subject request (stage-12 plan, Slice
 * 2; task breakdown item 6: "the export branch ... dispatches a queued
 * job that claims the request via the pending -> processing conditional
 * UPDATE"), mirroring App\Reporting\Jobs\BuildExportJob's own bootstrap
 * exactly: the tenant_id is read under the cross-tenant platform role
 * (SELECT only, data_subject_requests' own platform-read RLS policy
 * already grants this) because the job cannot set app.tenant_id before
 * it knows which tenant the request belongs to; every write happens
 * afterward, inside that tenant's own transaction.
 *
 * A vanished request (deleted between dispatch and this job running,
 * which nothing in this stage does today) exits cleanly rather than
 * throwing, the same defensive posture BuildExportJob takes on the
 * identical case.
 */
final class BuildDataSubjectExportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $requestId) {}

    public function handle(TenantTransaction $transactions, BuildDataSubjectExport $build): void
    {
        $tenantId = $transactions->asPlatform(
            fn (): ?string => DataSubjectRequest::query()->whereKey($this->requestId)->value('tenant_id'),
        );

        if ($tenantId === null) {
            return;
        }

        $transactions->asTenant($tenantId, fn () => $build($this->requestId));
    }
}
