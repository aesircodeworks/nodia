<?php

namespace App\Identity\Actions;

use App\Identity\Enums\DataSubjectRequestStatus;
use App\Identity\Enums\DataSubjectRequestType;
use App\Identity\Models\DataSubjectRequest;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\Date;
use RuntimeException;

/**
 * The data subject export attachment pruner (stage-12 plan, Slice 3,
 * task breakdown item 7; Data model "data_subject_requests": "Export
 * files ... are themselves subject to a short retention window because
 * they contain PII"). Candidates are completed export requests whose
 * completed_at is older than config('retention.export_attachment_days'),
 * discovered under the cross-tenant platform read policy T1 gave
 * data_subject_requests, mirroring
 * App\Inventory\Actions\ReleaseExpiredHolds' own candidate-discovery
 * shape. Deleting each attachment then runs entirely under the same
 * platform posture rather than per-tenant: the `media` table's own
 * platformWrite policy (stage-05c migration) already lets nodia_platform
 * delete any tenant's media row, unlike gateway_webhook_events or
 * holds, which carry no such grant.
 *
 * The request row itself is never written to: it survives as the audit
 * trail the plan names, and GET /v1/data-subject-requests/{id} already
 * derives download_url from whether a data_subject_export medialibrary
 * attachment exists, so deleting the attachment alone makes that field
 * read null from then on with no extra bookkeeping.
 *
 * Deleting through the Eloquent model, one attachment at a time, rather
 * than a bulk query against the `media` table, is deliberate: medialibrary
 * removes the stored object and its conversions from a MediaObserver
 * hook wired to Model::delete(), which a bulk DELETE bypasses entirely.
 */
final readonly class PruneDataSubjectExportAttachments
{
    public function __construct(
        private TenantTransaction $transactions,
    ) {}

    public function __invoke(): int
    {
        $days = config()->integer('retention.export_attachment_days');

        if ($days <= 0) {
            throw new RuntimeException(
                "retention.export_attachment_days must be a positive number of days to run the export attachment pruner, got {$days}.",
            );
        }

        $cutoff = Date::now()->subDays($days);

        return $this->transactions->asPlatform(function () use ($cutoff): int {
            $requestIds = DataSubjectRequest::query()
                ->select('id')
                ->where('type', DataSubjectRequestType::Export)
                ->where('status', DataSubjectRequestStatus::Completed)
                ->where('completed_at', '<=', $cutoff)
                ->pluck('id');

            $pruned = 0;

            foreach ($requestIds as $requestId) {
                $request = DataSubjectRequest::query()->find($requestId);

                if ($request === null) {
                    continue;
                }

                $media = $request->getFirstMedia('data_subject_export');

                if ($media === null) {
                    continue;
                }

                $media->delete();
                $pruned++;
            }

            return $pruned;
        });
    }
}
