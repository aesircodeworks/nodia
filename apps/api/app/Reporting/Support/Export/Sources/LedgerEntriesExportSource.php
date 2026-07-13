<?php

namespace App\Reporting\Support\Export\Sources;

use App\Payments\Actions\PaginateLedgerEntriesForExport;
use App\Payments\Data\LedgerEntryExportRowData;
use App\Reporting\Enums\ExportType;
use App\Reporting\Support\Export\ExportSource;

/**
 * The `ledger_entries` export (stage-11 plan, task 15/T12). Every row
 * comes from App\Payments\Actions\PaginateLedgerEntriesForExport, never
 * from an App\Payments\Models\LedgerEntry query in this file, so
 * Reporting never touches the ledger_entries table directly.
 *
 * `event_id` is declared `prohibited`, not merely absent from the rule
 * set: ledger_entries carries no event_id column and is not naturally
 * scoped to one event (a payout leg in particular aggregates across many
 * orders), the same reason
 * App\Payments\Actions\PaginateLedgerEntriesForExport's own docblock
 * gives, and the reason the existing GET /v1/ledger-entries endpoint has
 * no event_id filter either. A caller that supplies it gets a real
 * validation error, not a silently ignored parameter.
 */
final readonly class LedgerEntriesExportSource implements ExportSource
{
    public function __construct(private PaginateLedgerEntriesForExport $paginate) {}

    public function type(): ExportType
    {
        return ExportType::LedgerEntries;
    }

    public function rules(): array
    {
        return [
            'event_id' => ['prohibited'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
        ];
    }

    /**
     * $tenantId goes unused: RLS already scopes LedgerEntry::query() to
     * the tenant transaction App\Reporting\Jobs\BuildExportJob opened
     * before this method is ever called (see ExportSource's own
     * docblock).
     */
    public function pages(string $tenantId, array $parameters): iterable
    {
        return ($this->paginate)(
            $parameters['from'] ?? null,
            $parameters['to'] ?? null,
        );
    }

    public function columns(): array
    {
        return [
            'id' => static fn (LedgerEntryExportRowData $row): string => $row->id,
            'account' => static fn (LedgerEntryExportRowData $row): string => $row->account,
            'direction' => static fn (LedgerEntryExportRowData $row): string => $row->direction,
            'amount' => static fn (LedgerEntryExportRowData $row): int => $row->amount->amount,
            'currency' => static fn (LedgerEntryExportRowData $row): string => $row->amount->currency,
            'reference_type' => static fn (LedgerEntryExportRowData $row): string => $row->referenceType,
            'reference_id' => static fn (LedgerEntryExportRowData $row): string => $row->referenceId,
            'created_at' => static fn (LedgerEntryExportRowData $row): string => $row->createdAt,
        ];
    }
}
