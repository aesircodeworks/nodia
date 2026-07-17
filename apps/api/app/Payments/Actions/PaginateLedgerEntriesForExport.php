<?php

namespace App\Payments\Actions;

use App\Payments\Data\LedgerEntryExportRowData;
use App\Payments\Models\LedgerEntry;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;

/**
 * The cursor-paginated Payments Action behind the `ledger_entries` export
 * source (stage-11 plan, task 15/T12), mirroring
 * App\Orders\Actions\PaginateOrdersForExport's own shape: yields one
 * page of LedgerEntryExportRowData at a time so
 * App\Reporting\Support\Export\CsvExportWriter never holds more than one
 * page of rows in memory.
 *
 * No $eventId parameter: ledger_entries carries no event_id column, and
 * a ledger entry is not naturally scoped to one event the way an order
 * or a ticket is (system-design 7.3: the ledger is the tenant's own
 * double-entry books; a payout leg in particular aggregates across many
 * orders and has no single owning event at all). The existing
 * GET /v1/ledger-entries read endpoint (App\Payments\Http\Controllers
 * \LedgerController) has no event_id filter either, for the same reason.
 * App\Reporting\Support\Export\Sources\LedgerEntriesExportSource
 * declares this explicitly by marking `event_id` `prohibited` in its own
 * rules(), rather than silently ignoring it.
 *
 * $from and $to bound `created_at` directly (both inclusive), matching
 * LedgerController's own `created_at_from`/`created_at_to` filters
 * against this exact column.
 *
 * $perPage defaults to the same 500 PaginateOrdersForExport uses in
 * production, but is a constructor argument here (not a private
 * constant) so tests can seed a handful of rows and still prove genuine
 * per-page querying without needing 500+ rows to force a second page.
 */
final class PaginateLedgerEntriesForExport
{
    private const int DEFAULT_PER_PAGE = 500;

    public function __construct(private readonly int $perPage = self::DEFAULT_PER_PAGE) {}

    /**
     * @return Generator<int, list<LedgerEntryExportRowData>>
     */
    public function __invoke(?string $from, ?string $to): Generator
    {
        $cursor = null;

        do {
            $page = LedgerEntry::query()
                ->when($from !== null, fn (Builder $query): Builder => $query->where('created_at', '>=', $from))
                ->when($to !== null, fn (Builder $query): Builder => $query->where('created_at', '<=', $to))
                ->orderBy('id')
                ->cursorPaginate($this->perPage, ['*'], 'cursor', $cursor);

            yield $page->getCollection()
                ->map(fn (LedgerEntry $entry): LedgerEntryExportRowData => LedgerEntryExportRowData::fromModel($entry))
                ->all();

            $cursor = $page->hasMorePages() ? $page->nextCursor() : null;
        } while ($cursor instanceof Cursor);
    }
}
