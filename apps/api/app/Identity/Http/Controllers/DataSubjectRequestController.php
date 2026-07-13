<?php

namespace App\Identity\Http\Controllers;

use App\Identity\Actions\CreateDataSubjectRequest;
use App\Identity\Authorization\CapabilityGate;
use App\Identity\Capability;
use App\Identity\Data\CreateDataSubjectRequestData;
use App\Identity\Data\DataSubjectRequestData;
use App\Identity\Enums\DataSubjectRequestStatus;
use App\Identity\Enums\DataSubjectRequestType;
use App\Identity\Exceptions\CustomerNotFoundException;
use App\Identity\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /v1/customers/{customer}/data-subject-requests (stage-12 plan,
 * Endpoints; task breakdown item 3). The capability required depends on
 * the requested type (customers.erase for erasure, customers.export for
 * export), so unlike every other mutating route in this context it is not
 * gated by a static App\Http\Middleware\RequireCapability route
 * middleware entry; CapabilityGate is called directly once the request
 * body has resolved, mirroring RequireCapability's own delegation.
 * Erasure completes synchronously, so the response status tracks the
 * resulting request status rather than being hardcoded: 201 once it is
 * already completed, 202 for anything still in flight (Slice 2's export
 * path, once it lands).
 */
class DataSubjectRequestController
{
    public function store(
        string $customer,
        CreateDataSubjectRequestData $data,
        Request $request,
        CapabilityGate $gate,
        CreateDataSubjectRequest $create,
    ): JsonResponse {
        $gate->authorize(match ($data->type) {
            DataSubjectRequestType::Erasure => Capability::CustomersErase,
            DataSubjectRequestType::Export => Capability::CustomersExport,
        });

        $customerModel = Customer::query()->find($customer) ?? throw CustomerNotFoundException::forId($customer);

        $staff = $request->user('staff');

        $dataSubjectRequest = $create($customerModel, $data->type, (string) $staff?->getAuthIdentifier());

        $status = $dataSubjectRequest->status === DataSubjectRequestStatus::Completed ? 201 : 202;

        return response()->json(DataSubjectRequestData::fromModel($dataSubjectRequest), $status);
    }
}
