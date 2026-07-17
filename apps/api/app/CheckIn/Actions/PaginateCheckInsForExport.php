<?php

namespace App\CheckIn\Actions;

use App\CheckIn\Data\CheckInExportRowData;
use App\CheckIn\Models\CheckIn;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;

/**
 * The cursor-paginated CheckIn Action behind the `check_ins` export
 * source (stage-11 plan, task 15/T12), mirroring
 * App\Orders\Actions\PaginateOrdersForExport's own shape: yields one
 * page of CheckInExportRowData at a time so
 * App\Reporting\Support\Export\CsvExportWriter never holds more than one
 * page of rows in memory.
 *
 * $from and $to bound `scanned_at` directly (both inclusive), the scan
 * instant rather than `created_at` or `synced_at`, since scanned_at is
 * the attendance fact a check-ins export is about.
 *
 * $perPage defaults to the same 500 PaginateOrdersForExport uses in
 * production, but is a constructor argument here (not a private
 * constant) so tests can seed a handful of rows and still prove genuine
 * per-page querying without needing 500+ rows to force a second page.
 */
final class PaginateCheckInsForExport
{
    private const int DEFAULT_PER_PAGE = 500;

    public function __construct(private readonly int $perPage = self::DEFAULT_PER_PAGE) {}

    /**
     * @return Generator<int, list<CheckInExportRowData>>
     */
    public function __invoke(?string $eventId, ?string $from, ?string $to): Generator
    {
        $cursor = null;

        do {
            $page = CheckIn::query()
                ->when($eventId !== null, fn (Builder $query): Builder => $query->where('event_id', $eventId))
                ->when($from !== null, fn (Builder $query): Builder => $query->where('scanned_at', '>=', $from))
                ->when($to !== null, fn (Builder $query): Builder => $query->where('scanned_at', '<=', $to))
                ->orderBy('id')
                ->cursorPaginate($this->perPage, ['*'], 'cursor', $cursor);

            yield $page->getCollection()
                ->map(fn (CheckIn $checkIn): CheckInExportRowData => CheckInExportRowData::fromModel($checkIn))
                ->all();

            $cursor = $page->hasMorePages() ? $page->nextCursor() : null;
        } while ($cursor instanceof Cursor);
    }
}
