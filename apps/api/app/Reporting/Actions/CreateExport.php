<?php

namespace App\Reporting\Actions;

use App\Reporting\Data\ExportParametersData;
use App\Reporting\Enums\ExportStatus;
use App\Reporting\Enums\ExportType;
use App\Reporting\Jobs\BuildExportJob;
use App\Reporting\Models\Export;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * POST /v1/exports (stage-11 plan, Endpoints; task 16). Creates the
 * pending export row and defers dispatching App\Reporting\Jobs\
 * BuildExportJob to the enclosing request transaction's commit, exactly
 * mirroring App\Support\Outbox\OutboxDispatcher's and
 * App\Identity\Actions\InviteUser's own DB::afterCommit() posture,
 * rather than dispatching inline: the request itself always runs inside
 * an open tenant transaction (App\Tenancy\Http\Middleware\
 * ResolveTenantFromHeader), and App\Support\Tenancy\TenantTransaction::
 * run() rejects a nested tenant transaction, which is exactly what
 * BuildExportJob's own asPlatform()/asTenant() bootstrap would attempt
 * if it ran synchronously (QUEUE_CONNECTION=sync in every test and dev
 * environment in this codebase) before this request's own transaction
 * has committed and cleared its TenantContext.
 */
final readonly class CreateExport
{
    public function __construct(private TenantContext $context) {}

    public function __invoke(ExportType $type, ExportParametersData $parameters, string $requestedByUserId): Export
    {
        $export = Export::query()->create([
            'tenant_id' => $this->context->tenantId(),
            'type' => $type,
            'status' => ExportStatus::Pending,
            'parameters' => $parameters->toArray(),
            'requested_by_user_id' => $requestedByUserId,
        ]);

        DB::afterCommit(static fn () => BuildExportJob::dispatch($export->id));

        return $export;
    }
}
