<?php

namespace App\Identity\Actions;

use App\Identity\Enums\DataSubjectRequestStatus;
use App\Identity\Enums\DataSubjectRequestType;
use App\Identity\Exceptions\DataSubjectRequestAlreadyOpenException;
use App\Identity\Jobs\BuildDataSubjectExportJob;
use App\Identity\Models\Customer;
use App\Identity\Models\DataSubjectRequest;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * POST /v1/customers/{customer}/data-subject-requests (stage-12 plan,
 * Endpoints; task breakdown items 3 and 6). Creates the auditable
 * request row, then drives each type down its own path. The
 * data_subject_requests_open_per_customer_idx partial unique index is
 * the concurrent-submission guard for the request row itself for both
 * types, translated here by index name rather than a read-then-write
 * existence check (CLAUDE.md), mirroring App\Identity\Actions\CreateRole's
 * own precedent.
 *
 * Erasure claims the row (pending to processing) and runs synchronously
 * inside this same request's ambient tenant transaction (App\Tenancy\
 * Http\Middleware\ResolveTenantFromHeader), so no explicit failure
 * handling is needed: if AnonymizeCustomer's own conditional UPDATE
 * finds the customer already anonymized, the thrown exception unwinds
 * this whole transaction (App\Tenancy\Http\Middleware\
 * TransactsRequests), leaving neither the request row this call just
 * inserted nor any outbox row behind, which is exactly "a failed request
 * records nothing" (stage-12 plan, Slice 1 Feature tests).
 *
 * Export leaves the row pending and defers dispatching
 * App\Identity\Jobs\BuildDataSubjectExportJob to this request's
 * transaction commit, exactly mirroring App\Reporting\Actions\
 * CreateExport's own DB::afterCommit() posture for the identical
 * reason: the request's own ambient transaction has not committed yet,
 * and App\Support\Tenancy\TenantTransaction::run() rejects a nested
 * tenant transaction, which is exactly what the job's own
 * asPlatform()/asTenant() bootstrap would attempt if it ran
 * synchronously (QUEUE_CONNECTION=sync in every test and dev
 * environment in this codebase) before this request's transaction has
 * committed. The job itself claims the row (pending to processing) once
 * it runs, so the response returned here always carries status pending
 * for export, tracking the row's real state rather than the eventual
 * one (stage-12 plan, Endpoints: "Export returns 202 with pending").
 */
final readonly class CreateDataSubjectRequest
{
    public function __construct(
        private TenantContext $tenantContext,
        private AnonymizeCustomer $anonymize,
    ) {}

    public function __invoke(Customer $customer, DataSubjectRequestType $type, string $requestedByUserId): DataSubjectRequest
    {
        try {
            $request = DataSubjectRequest::create([
                'tenant_id' => $this->tenantContext->tenantId(),
                'customer_id' => $customer->id,
                'type' => $type,
                'status' => DataSubjectRequestStatus::Pending,
                'requested_by_user_id' => $requestedByUserId,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'data_subject_requests_open_per_customer_idx')) {
                throw DataSubjectRequestAlreadyOpenException::make();
            }

            throw $e;
        }

        return match ($type) {
            DataSubjectRequestType::Erasure => $this->runErasure($customer, $request),
            DataSubjectRequestType::Export => $this->dispatchExport($request),
        };
    }

    private function runErasure(Customer $customer, DataSubjectRequest $request): DataSubjectRequest
    {
        DataSubjectRequest::claim($request->id);

        ($this->anonymize)($customer, $request->id);

        DataSubjectRequest::complete($request->id);

        return $request->refresh();
    }

    private function dispatchExport(DataSubjectRequest $request): DataSubjectRequest
    {
        DB::afterCommit(static fn () => BuildDataSubjectExportJob::dispatch($request->id));

        return $request;
    }
}
