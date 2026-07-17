<?php

namespace App\Identity\Http\Controllers;

use App\Identity\Data\CustomerSummaryData;
use App\Identity\Models\Customer;
use Illuminate\Http\Request;
use Spatie\LaravelData\CursorPaginatedDataCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The staff customer lookup (stage-07 plan, Endpoints "GET
 * /v1/customers"): implemented in Identity since Orders never touches
 * customers. tenant_isolation RLS already scopes results to the acting
 * tenant; allowed query-builder parameters are filter[email] (exact)
 * and filter[name] (prefix); unknown ones are rejected with 400
 * invalid_query_parameter. Customers are unbounded, so the list cursor-
 * paginates (api-conventions, high-volume collections) over id desc:
 * ids are UUIDv7, so descending id is descending creation time, and the
 * cursor paginator needs its order columns present on the transformed
 * CustomerSummaryData items when it derives the next cursor.
 */
class CustomerLookupController
{
    public function index(Request $request): CursorPaginatedDataCollection
    {
        $customers = QueryBuilder::for(Customer::class)
            ->allowedFilters(
                AllowedFilter::exact('email'),
                AllowedFilter::beginsWith('name'),
            )
            ->orderByDesc('id')
            ->cursorPaginate(min($request->integer('per_page', 15), 100))
            ->appends($request->query());

        return CustomerSummaryData::collect($customers, CursorPaginatedDataCollection::class);
    }
}
