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
use App\Identity\Exceptions\DataSubjectRequestNotFoundException;
use App\Identity\Models\Customer;
use App\Identity\Models\DataSubjectRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Spatie\LaravelData\PaginatedDataCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

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
 *
 * GET /v1/data-subject-requests/{data_subject_request} and GET
 * /v1/data-subject-requests (task breakdown item 6's own download-URL,
 * show, and list half) are read-only surfaces available to either
 * capability holder (CapabilityGate::authorizeAny), unlike store()'s
 * type-dependent single-capability check: a caller who can only erase or
 * only export still needs to see the requests they can act on.
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

    public function show(string $dataSubjectRequest, CapabilityGate $gate): DataSubjectRequestData
    {
        $gate->authorizeAny(Capability::CustomersErase, Capability::CustomersExport);

        $model = DataSubjectRequest::query()->find($dataSubjectRequest)
            ?? throw DataSubjectRequestNotFoundException::forId($dataSubjectRequest);

        return DataSubjectRequestData::fromModel($model, $this->downloadUrl($model));
    }

    /**
     * Page pagination with the standard Laravel paginator envelope
     * (stage-12 plan, Endpoints: "Bounded collection, page pagination,
     * standard paginator envelope"), mirroring App\Identity\Http\
     * Controllers\RoleController's own posture. download_url stays null
     * for every row here: computing a signed URL per listed row would
     * mean a medialibrary lookup per row on every page, which the show
     * endpoint above already serves as the one documented way to fetch
     * it.
     *
     * @return PaginatedDataCollection<int, DataSubjectRequestData>
     */
    public function index(Request $request, CapabilityGate $gate): PaginatedDataCollection
    {
        $gate->authorizeAny(Capability::CustomersErase, Capability::CustomersExport);

        $requests = QueryBuilder::for(DataSubjectRequest::class)
            ->allowedFilters(
                AllowedFilter::exact('customer_id'),
                AllowedFilter::exact('type'),
                AllowedFilter::exact('status'),
            )
            ->allowedSorts('created_at')
            ->defaultSort('-created_at')
            ->paginate()
            ->appends($request->query());

        return DataSubjectRequestData::collect($requests, PaginatedDataCollection::class);
    }

    /**
     * A completed export always has an attached file: App\Identity\
     * Actions\BuildDataSubjectExport only transitions to completed after
     * the medialibrary attach succeeds. An erasure request never
     * attaches anything, so this returns null for every erasure
     * regardless of status, and for an export not yet completed, without
     * needing to inspect $model->type or $model->status directly: the
     * media collection's own presence is the single source of truth.
     */
    private function downloadUrl(DataSubjectRequest $model): ?string
    {
        $media = $model->getFirstMedia('data_subject_export');

        if ($media === null) {
            return null;
        }

        $expiration = Date::now()->addMinutes(config()->integer('identity.data_subject_export_download_url_ttl_minutes'));

        return $media->getTemporaryUrl($expiration);
    }
}
