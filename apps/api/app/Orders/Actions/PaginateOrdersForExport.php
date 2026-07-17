<?php

namespace App\Orders\Actions;

use App\Orders\Data\OrderExportRowData;
use App\Orders\Models\Order;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;

/**
 * The cursor-paginated Orders Action behind the `orders` export source
 * (stage-11 plan, task 15: "Ship exactly one source, orders, over a new
 * cursor-paginated Orders Action ... so Reporting never touches the
 * orders tables directly"). Yields one page of OrderExportRowData at a
 * time so App\Reporting\Support\Export\CsvExportWriter never holds more
 * than one page of rows in memory; the next page is not queried until
 * the caller resumes this generator, which only happens after it has
 * finished writing every row of the current one.
 *
 * $from and $to bound `created_at` directly (both inclusive), the same
 * comparison App\Orders\Http\Controllers\StaffOrderController's own
 * `created_from`/`created_to` filters already use against this exact
 * column, not the whereDate() comparison the daily-sales endpoint uses
 * against its own date-typed sales_date column.
 */
final class PaginateOrdersForExport
{
    private const int PER_PAGE = 500;

    /**
     * @return Generator<int, list<OrderExportRowData>>
     */
    public function __invoke(?string $eventId, ?string $from, ?string $to): Generator
    {
        $cursor = null;

        do {
            $page = Order::query()
                ->when($eventId !== null, fn (Builder $query): Builder => $query->where('event_id', $eventId))
                ->when($from !== null, fn (Builder $query): Builder => $query->where('created_at', '>=', $from))
                ->when($to !== null, fn (Builder $query): Builder => $query->where('created_at', '<=', $to))
                ->orderBy('id')
                ->cursorPaginate(self::PER_PAGE, ['*'], 'cursor', $cursor);

            yield $page->getCollection()
                ->map(fn (Order $order): OrderExportRowData => OrderExportRowData::fromModel($order))
                ->all();

            $cursor = $page->hasMorePages() ? $page->nextCursor() : null;
        } while ($cursor instanceof Cursor);
    }
}
