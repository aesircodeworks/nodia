<?php

namespace App\Orders\Actions;

use App\Orders\Data\OrderExportRowData;
use App\Orders\Models\Order;
use Generator;
use Illuminate\Pagination\Cursor;

/**
 * The cursor-paginated Orders Action behind the data subject export
 * assembler's `orders` category (stage-12 plan, Slice 2; task
 * breakdown item 6). Filters by customer_id rather than event_id/date
 * window, the shape App\Orders\Actions\PaginateOrdersForExport already
 * covers for Reporting, so this is a new Action rather than a widened
 * PaginateOrdersForExport: the two callers filter on genuinely
 * different columns and nothing is gained by forcing one signature to
 * serve both. It does reuse App\Orders\Data\OrderExportRowData
 * unchanged, the same row shape PaginateOrdersForExport already yields,
 * since a data subject export's "orders" category needs exactly the
 * same per-order facts a reporting export does.
 *
 * Yields one page of OrderExportRowData at a time, mirroring
 * PaginateOrdersForExport's own generator shape, so
 * App\Identity\Actions\BuildDataSubjectExport never holds more than one
 * page of rows in memory while it assembles the document (api-
 * conventions' high-volume cursor-pagination rule).
 */
final class PaginateOrdersForDataSubjectExport
{
    private const int DEFAULT_PER_PAGE = 500;

    public function __construct(private readonly int $perPage = self::DEFAULT_PER_PAGE) {}

    /**
     * @return Generator<int, list<OrderExportRowData>>
     */
    public function __invoke(string $customerId): Generator
    {
        $cursor = null;

        do {
            $page = Order::query()
                ->where('customer_id', $customerId)
                ->orderBy('id')
                ->cursorPaginate($this->perPage, ['*'], 'cursor', $cursor);

            yield $page->getCollection()
                ->map(fn (Order $order): OrderExportRowData => OrderExportRowData::fromModel($order))
                ->all();

            $cursor = $page->hasMorePages() ? $page->nextCursor() : null;
        } while ($cursor instanceof Cursor);
    }
}
