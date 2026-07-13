<?php

namespace App\CheckIn\Actions;

use App\CheckIn\Data\CheckInExportRowData;
use App\CheckIn\Models\CheckIn;
use Generator;
use Illuminate\Pagination\Cursor;

/**
 * The cursor-paginated CheckIn Action behind the data subject export
 * assembler's `check_ins` category (stage-12 plan, Slice 2; task
 * breakdown item 6), mirroring
 * App\CheckIn\Actions\PaginateCheckInsForExport's own generator shape.
 * check_ins carries no customer_id column (only ticket_id and
 * event_id), so the caller (App\Identity\Actions\
 * BuildDataSubjectExport) resolves the customer's ticket IDs through
 * Orders' own PaginateTicketsForDataSubjectExport first and passes them
 * in here, the same "load it through the owning context's Actions"
 * posture App\Orders\Actions\GetTicketTypeIds already established for a
 * bulk ID lookup that crosses into CheckIn, just cursor-paginated
 * instead of a single bulk pluck because a check-in row set is exactly
 * the kind of collection api-conventions' high-volume rule covers.
 * Reuses App\CheckIn\Data\CheckInExportRowData unchanged.
 */
final class PaginateCheckInsForDataSubjectExport
{
    private const int DEFAULT_PER_PAGE = 500;

    public function __construct(private readonly int $perPage = self::DEFAULT_PER_PAGE) {}

    /**
     * @param  list<string>  $ticketIds
     * @return Generator<int, list<CheckInExportRowData>>
     */
    public function __invoke(array $ticketIds): Generator
    {
        if ($ticketIds === []) {
            return;
        }

        $cursor = null;

        do {
            $page = CheckIn::query()
                ->whereIn('ticket_id', $ticketIds)
                ->orderBy('id')
                ->cursorPaginate($this->perPage, ['*'], 'cursor', $cursor);

            yield $page->getCollection()
                ->map(fn (CheckIn $checkIn): CheckInExportRowData => CheckInExportRowData::fromModel($checkIn))
                ->all();

            $cursor = $page->hasMorePages() ? $page->nextCursor() : null;
        } while ($cursor instanceof Cursor);
    }
}
