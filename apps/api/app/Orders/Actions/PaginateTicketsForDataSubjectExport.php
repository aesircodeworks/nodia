<?php

namespace App\Orders\Actions;

use App\Orders\Data\TicketExportRowData;
use App\Orders\Models\Order;
use App\Orders\Models\Ticket;
use Generator;
use Illuminate\Pagination\Cursor;

/**
 * The cursor-paginated Orders Action behind the data subject export
 * assembler's `tickets` category (stage-12 plan, Slice 2; task
 * breakdown item 6), mirroring
 * App\Orders\Actions\PaginateOrdersForDataSubjectExport's own reasoning:
 * a customer's tickets are scoped through their orders (tickets carry no
 * customer_id column of their own), a filter shape
 * App\Orders\Actions\PaginateTicketsForExport does not cover, so this is
 * a new Action rather than a widened one. Reuses
 * App\Orders\Data\TicketExportRowData unchanged, including the
 * GetTicketSaleFacts-resolved list price, the same row shape
 * PaginateTicketsForExport already yields.
 *
 * The order_id subquery stays inside Orders' own two tables (orders,
 * tickets); no cross-context join, matching the context-boundary rule
 * (a context never queries another context's tables) even though both
 * tables in this query happen to be owned by this same context.
 */
final class PaginateTicketsForDataSubjectExport
{
    private const int DEFAULT_PER_PAGE = 500;

    public function __construct(
        private readonly GetTicketSaleFacts $saleFacts,
        private readonly int $perPage = self::DEFAULT_PER_PAGE,
    ) {}

    /**
     * @return Generator<int, list<TicketExportRowData>>
     */
    public function __invoke(string $customerId): Generator
    {
        $cursor = null;

        do {
            $page = Ticket::query()
                ->whereIn('order_id', Order::query()->where('customer_id', $customerId)->select('id'))
                ->orderBy('id')
                ->cursorPaginate($this->perPage, ['*'], 'cursor', $cursor);

            $tickets = $page->getCollection();
            $facts = ($this->saleFacts)($tickets->pluck('id')->all());

            yield $tickets
                ->map(fn (Ticket $ticket): TicketExportRowData => TicketExportRowData::fromModel(
                    $ticket,
                    $facts->get($ticket->id)?->listPrice,
                ))
                ->all();

            $cursor = $page->hasMorePages() ? $page->nextCursor() : null;
        } while ($cursor instanceof Cursor);
    }
}
