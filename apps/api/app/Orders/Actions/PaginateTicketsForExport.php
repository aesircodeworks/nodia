<?php

namespace App\Orders\Actions;

use App\Orders\Data\TicketExportRowData;
use App\Orders\Models\Ticket;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;

/**
 * The cursor-paginated Orders Action behind the `tickets` export source
 * (stage-11 plan, task 15/T12), mirroring PaginateOrdersForExport's own
 * shape: yields one page of TicketExportRowData at a time so
 * App\Reporting\Support\Export\CsvExportWriter never holds more than one
 * page of rows in memory.
 *
 * $from and $to bound `issued_at` directly (both inclusive), the same
 * direct-timestamp-comparison posture PaginateOrdersForExport takes
 * against `created_at`, not the whereDate() comparison the daily-sales
 * endpoint uses against its own date-typed column.
 *
 * $perPage defaults to the same 500 PaginateOrdersForExport uses in
 * production, but is a constructor argument here (not a private
 * constant) so tests can seed a handful of rows and still prove genuine
 * per-page querying (tests/Unit/Orders/PaginateTicketsForExportTest.php,
 * tests/Unit/Reporting/TicketsExportSourceTest.php) without needing 500+
 * rows to force a second page.
 */
final class PaginateTicketsForExport
{
    private const int DEFAULT_PER_PAGE = 500;

    public function __construct(
        private readonly GetTicketSaleFacts $saleFacts,
        private readonly int $perPage = self::DEFAULT_PER_PAGE,
    ) {}

    /**
     * @return Generator<int, list<TicketExportRowData>>
     */
    public function __invoke(?string $eventId, ?string $from, ?string $to): Generator
    {
        $cursor = null;

        do {
            $page = Ticket::query()
                ->when($eventId !== null, fn (Builder $query): Builder => $query->where('event_id', $eventId))
                ->when($from !== null, fn (Builder $query): Builder => $query->where('issued_at', '>=', $from))
                ->when($to !== null, fn (Builder $query): Builder => $query->where('issued_at', '<=', $to))
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
