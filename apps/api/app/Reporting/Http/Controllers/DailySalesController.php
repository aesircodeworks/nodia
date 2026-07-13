<?php

namespace App\Reporting\Http\Controllers;

use App\Reporting\Data\DailySalesData;
use App\Reporting\Models\DailySales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\LaravelData\CursorPaginatedDataCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The daily sales dashboard read (stage-11 plan, Endpoints "GET
 * /v1/reports/daily-sales"; TDD sequencing Slice 2). tenant_isolation RLS
 * already scopes the result to the acting tenant's own rows. Allowed
 * query-builder parameters are filter[event_id], filter[ticket_type_id]
 * (exact), filter[from], filter[to] (inclusive sales_date bounds), and
 * sort in (sales_date, -sales_date); unknown ones are rejected with 400
 * invalid_query_parameter, never ignored. Cursor-paginated with a
 * deterministic (sales_date, id) order: the id tiebreak orders rows that
 * share a sales_date, and defaultSort keeps that order stable when the
 * caller supplies no sort at all (App\Reporting\Data\DailySalesData's own
 * docblock explains why id, though never on the wire, must still be a
 * real property on that class for the cursor to encode correctly).
 */
class DailySalesController
{
    public function index(Request $request): CursorPaginatedDataCollection
    {
        $rows = QueryBuilder::for(DailySales::class)
            ->allowedFilters(
                AllowedFilter::exact('event_id'),
                AllowedFilter::exact('ticket_type_id'),
                AllowedFilter::callback('from', fn (Builder $query, mixed $value) => $query->whereDate('sales_date', '>=', (string) $value)),
                AllowedFilter::callback('to', fn (Builder $query, mixed $value) => $query->whereDate('sales_date', '<=', (string) $value)),
            )
            ->allowedSorts('sales_date')
            ->defaultSort('sales_date')
            ->orderBy('id')
            ->cursorPaginate(min($request->integer('per_page', 15), 100))
            ->appends($request->query());

        return DailySalesData::collect($rows, CursorPaginatedDataCollection::class);
    }
}
