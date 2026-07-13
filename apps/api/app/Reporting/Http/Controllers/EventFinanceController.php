<?php

namespace App\Reporting\Http\Controllers;

use App\Reporting\Data\EventFinanceData;
use App\Reporting\Models\EventFinance;
use Illuminate\Http\Request;
use Spatie\LaravelData\CursorPaginatedDataCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The per-event finance dashboard read (stage-11 plan, Endpoints "GET
 * /v1/reports/event-finance"; TDD sequencing Slice 4, same pattern as
 * Slice 2). tenant_isolation RLS already scopes the result to the
 * acting tenant's own rows. The only allowed query-builder parameter is
 * filter[event_id] (exact); the endpoint's own Endpoints table row names
 * no sort, so allowedSorts() is called with no arguments to reject any
 * requested sort with 400 invalid_query_parameter (mirroring
 * LedgerController::entries's own no-sort-allowed posture), never
 * silently ignoring it. Cursor-paginated ordered by event_id alone:
 * report_event_finance's own unique(tenant_id, event_id) constraint
 * makes that single column fully deterministic under RLS tenant
 * scoping, unlike report_daily_sales, which needs an id tiebreak.
 */
class EventFinanceController
{
    public function index(Request $request): CursorPaginatedDataCollection
    {
        $rows = QueryBuilder::for(EventFinance::class)
            ->allowedFilters(
                AllowedFilter::exact('event_id'),
            )
            ->allowedSorts()
            ->orderBy('event_id')
            ->cursorPaginate(min($request->integer('per_page', 15), 100))
            ->appends($request->query());

        return EventFinanceData::collect($rows, CursorPaginatedDataCollection::class);
    }
}
