<?php

namespace App\Identity\Actions;

use App\Identity\Enums\DataSubjectRequestStatus;
use App\Identity\Enums\DataSubjectRequestType;
use App\Identity\Exceptions\DataSubjectRequestAlreadyOpenException;
use App\Identity\Models\Customer;
use App\Identity\Models\DataSubjectRequest;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;

/**
 * POST /v1/customers/{customer}/data-subject-requests (stage-12 plan,
 * Endpoints; task breakdown item 3). Creates the auditable request row,
 * claims it (pending to processing, the same exactly-one-worker transition
 * every other data_subject_requests caller uses), and drives it to
 * completion. Erasure runs synchronously inside this same request's
 * ambient tenant transaction (App\Tenancy\Http\Middleware\
 * ResolveTenantFromHeader), so no explicit failure handling is needed
 * here: if AnonymizeCustomer's own conditional UPDATE finds the customer
 * already anonymized, the thrown exception unwinds this whole transaction
 * (App\Tenancy\Http\Middleware\TransactsRequests), leaving neither the
 * request row this call just inserted nor any outbox row behind, which is
 * exactly "a failed request records nothing" (stage-12 plan, Slice 1
 * Feature tests). The data_subject_requests_open_per_customer_idx partial
 * unique index is the concurrent-submission guard for the request row
 * itself, translated here by index name rather than a read-then-write
 * existence check (CLAUDE.md), mirroring App\Identity\Actions\CreateRole's
 * own precedent.
 *
 * The export arm below is unreachable today: App\Identity\Data\
 * CreateDataSubjectRequestData restricts the incoming type to erasure
 * only until Slice 2 (task 6) lands the export assembler and queued job,
 * mirroring App\Reporting\Data\CreateExportData's own registered-types
 * restriction. It stays written out, not collapsed to erasure-only logic,
 * so the match stays exhaustive over DataSubjectRequestType and the
 * LogicException documents the gap rather than silently mishandling a
 * value that reaches here some other way.
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

        DataSubjectRequest::claim($request->id);

        match ($type) {
            DataSubjectRequestType::Erasure => ($this->anonymize)($customer, $request->id),
            DataSubjectRequestType::Export => throw new LogicException('Data subject export requests are not implemented yet.'),
        };

        DataSubjectRequest::complete($request->id);

        return $request->refresh();
    }
}
