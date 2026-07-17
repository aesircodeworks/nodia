<?php

namespace App\Payments\Actions;

use App\Payments\Data\RefundExportRowData;
use App\Payments\Models\Payment;
use App\Payments\Models\Refund;
use Generator;
use Illuminate\Pagination\Cursor;

/**
 * The cursor-paginated Payments Action behind the data subject export
 * assembler's `refunds` category (stage-12 plan, Slice 2; task
 * breakdown item 6), mirroring
 * App\Payments\Actions\PaginatePaymentsForDataSubjectExport's own
 * reasoning: refunds carry no order_id or customer_id column of their
 * own, only payment_id, so the customer's payment IDs are resolved
 * first, from the same order IDs the caller already passes in, entirely
 * within Payments' own two tables (payments, refunds) rather than a
 * cross-context join.
 */
final class PaginateRefundsForDataSubjectExport
{
    private const int DEFAULT_PER_PAGE = 500;

    public function __construct(private readonly int $perPage = self::DEFAULT_PER_PAGE) {}

    /**
     * @param  list<string>  $orderIds
     * @return Generator<int, list<RefundExportRowData>>
     */
    public function __invoke(array $orderIds): Generator
    {
        if ($orderIds === []) {
            return;
        }

        $paymentIds = Payment::query()->whereIn('order_id', $orderIds)->pluck('id')->all();

        if ($paymentIds === []) {
            return;
        }

        $cursor = null;

        do {
            $page = Refund::query()
                ->whereIn('payment_id', $paymentIds)
                ->orderBy('id')
                ->cursorPaginate($this->perPage, ['*'], 'cursor', $cursor);

            yield $page->getCollection()
                ->map(fn (Refund $refund): RefundExportRowData => RefundExportRowData::fromModel($refund))
                ->all();

            $cursor = $page->hasMorePages() ? $page->nextCursor() : null;
        } while ($cursor instanceof Cursor);
    }
}
