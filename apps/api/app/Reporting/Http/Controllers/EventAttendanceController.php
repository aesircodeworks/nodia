<?php

namespace App\Reporting\Http\Controllers;

use App\Reporting\Data\EventAttendanceData;
use App\Reporting\Models\EventAttendance;
use Illuminate\Http\Request;
use Spatie\LaravelData\CursorPaginatedDataCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The per-event attendance dashboard read (stage-11 plan, Endpoints "GET
 * /v1/reports/attendance"; TDD sequencing Slice 6, same pattern as
 * Slices 2 and 4). tenant_isolation RLS already scopes the result to
 * the acting tenant's own rows. The only allowed query-builder parameter
 * is filter[event_id] (exact); the endpoint's own Endpoints table row
 * names no sort, so allowedSorts() is called with no arguments to
 * reject any requested sort with 400 invalid_query_parameter, mirroring
 * EventFinanceController's own no-sort-allowed posture. Cursor-paginated
 * ordered by (event_id, ticket_type_id): report_event_attendance's own
 * unique(tenant_id, event_id, ticket_type_id) constraint makes that pair
 * fully deterministic under RLS tenant scoping, unlike
 * EventFinanceController's event_id-alone order, since one event has
 * many ticket types here.
 */
class EventAttendanceController
{
    public function index(Request $request): CursorPaginatedDataCollection
    {
        $rows = QueryBuilder::for(EventAttendance::class)
            ->allowedFilters(
                AllowedFilter::exact('event_id'),
            )
            ->allowedSorts()
            ->orderBy('event_id')
            ->orderBy('ticket_type_id')
            ->cursorPaginate(min($request->integer('per_page', 15), 100))
            ->appends($request->query());

        return EventAttendanceData::collect($rows, CursorPaginatedDataCollection::class);
    }
}
