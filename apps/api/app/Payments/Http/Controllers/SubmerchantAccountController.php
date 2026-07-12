<?php

namespace App\Payments\Http\Controllers;

use App\Payments\Actions\StartSubmerchantOnboarding;
use App\Payments\Data\StartSubmerchantOnboardingData;
use App\Payments\Data\SubmerchantAccountData;
use App\Payments\Exceptions\SubmerchantAccountNotFoundException;
use App\Payments\Models\SubmerchantAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\LaravelData\PaginatedDataCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The submerchant account onboarding and read surface (stage-08c plan,
 * Endpoints), capability-gated on payouts.manage (mutation) and
 * payouts.view (reads), both financially privileged so the admin group's
 * MFA enforcement applies.
 */
class SubmerchantAccountController
{
    public function store(StartSubmerchantOnboardingData $data, StartSubmerchantOnboarding $start): JsonResponse
    {
        return response()->json(SubmerchantAccountData::fromModel($start($data)), 201);
    }

    /**
     * Bounded per-tenant collection (at most one row per gateway), so
     * page pagination is acceptable per api-conventions. Allowed
     * query-builder parameters are filter[gateway], filter[status]
     * (exact) and sort=-created_at; unknown ones are rejected with 400
     * invalid_query_parameter, never ignored.
     *
     * @return PaginatedDataCollection<int, SubmerchantAccountData>
     */
    public function index(Request $request): PaginatedDataCollection
    {
        $accounts = QueryBuilder::for(SubmerchantAccount::class)
            ->allowedFilters(AllowedFilter::exact('gateway'), AllowedFilter::exact('status'))
            ->allowedSorts('created_at')
            ->defaultSort('-created_at')
            ->paginate()
            ->appends($request->query());

        return SubmerchantAccountData::collect($accounts, PaginatedDataCollection::class);
    }

    public function show(string $submerchantAccount): SubmerchantAccountData
    {
        $account = SubmerchantAccount::query()->find($submerchantAccount)
            ?? throw SubmerchantAccountNotFoundException::forId($submerchantAccount);

        return SubmerchantAccountData::fromModel($account);
    }
}
