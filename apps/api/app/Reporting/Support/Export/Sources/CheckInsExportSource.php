<?php

namespace App\Reporting\Support\Export\Sources;

use App\CheckIn\Actions\PaginateCheckInsForExport;
use App\CheckIn\Data\CheckInExportRowData;
use App\Reporting\Enums\ExportType;
use App\Reporting\Support\Export\ExportSource;

/**
 * The `check_ins` export (stage-11 plan, task 15/T12; ungated per this
 * task's own instruction since the attendance projector, task 11,
 * already landed in T7). Every row comes from
 * App\CheckIn\Actions\PaginateCheckInsForExport, never from an
 * App\CheckIn\Models\CheckIn query in this file, so Reporting never
 * touches the check_ins table directly.
 */
final readonly class CheckInsExportSource implements ExportSource
{
    public function __construct(private PaginateCheckInsForExport $paginate) {}

    public function type(): ExportType
    {
        return ExportType::CheckIns;
    }

    public function rules(): array
    {
        return [
            'event_id' => ['sometimes', 'nullable', 'uuid'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
        ];
    }

    /**
     * $tenantId goes unused: RLS already scopes CheckIn::query() to the
     * tenant transaction App\Reporting\Jobs\BuildExportJob opened before
     * this method is ever called (see ExportSource's own docblock).
     */
    public function pages(string $tenantId, array $parameters): iterable
    {
        return ($this->paginate)(
            $parameters['event_id'] ?? null,
            $parameters['from'] ?? null,
            $parameters['to'] ?? null,
        );
    }

    public function columns(): array
    {
        return [
            'id' => static fn (CheckInExportRowData $row): string => $row->id,
            'event_id' => static fn (CheckInExportRowData $row): string => $row->eventId,
            'ticket_id' => static fn (CheckInExportRowData $row): string => $row->ticketId,
            'result' => static fn (CheckInExportRowData $row): string => $row->result,
            'scanned_at' => static fn (CheckInExportRowData $row): string => $row->scannedAt,
        ];
    }
}
