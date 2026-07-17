<?php

namespace App\Payments\Actions;

use App\Payments\Data\PaymentExportRowData;
use App\Payments\Models\Payment;
use Generator;
use Illuminate\Pagination\Cursor;

/**
 * The cursor-paginated Payments Action behind the data subject export
 * assembler's `payments` category (stage-12 plan, Slice 2; task
 * breakdown item 6), mirroring
 * App\Payments\Actions\PaginateLedgerEntriesForExport's own generator
 * shape. Payments carries no customer_id column of its own (system-
 * design 7.3: a payment is a fact about an order, not directly about a
 * customer), so the caller (App\Identity\Actions\
 * BuildDataSubjectExport) resolves the customer's order IDs through
 * Orders' own PaginateOrdersForDataSubjectExport first and passes them
 * in here, the same "load it through the owning context's Actions"
 * posture App\Orders\Actions\GetOrderEventIds already established for a
 * bulk ID lookup, just cursor-paginated instead of a single bulk pluck
 * because a payment row set is exactly the kind of collection api-
 * conventions' high-volume rule covers.
 */
final class PaginatePaymentsForDataSubjectExport
{
    private const int DEFAULT_PER_PAGE = 500;

    public function __construct(private readonly int $perPage = self::DEFAULT_PER_PAGE) {}

    /**
     * @param  list<string>  $orderIds
     * @return Generator<int, list<PaymentExportRowData>>
     */
    public function __invoke(array $orderIds): Generator
    {
        if ($orderIds === []) {
            return;
        }

        $cursor = null;

        do {
            $page = Payment::query()
                ->whereIn('order_id', $orderIds)
                ->orderBy('id')
                ->cursorPaginate($this->perPage, ['*'], 'cursor', $cursor);

            yield $page->getCollection()
                ->map(fn (Payment $payment): PaymentExportRowData => PaymentExportRowData::fromModel($payment))
                ->all();

            $cursor = $page->hasMorePages() ? $page->nextCursor() : null;
        } while ($cursor instanceof Cursor);
    }
}
