<?php

namespace App\Reporting\Data;

use App\Reporting\Enums\ExportStatus;
use App\Reporting\Enums\ExportType;
use App\Reporting\Models\Export;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The export lifecycle wire shape (stage-11 plan, Endpoints
 * "ExportData: id, type, status, parameters, row_count, failure_code,
 * completed_at, created_at"). Unlike DailySalesData/EventFinanceData/
 * EventAttendanceData, id is a real, visible wire property here: exports
 * has no natural business key (T10's own migration note), and GET
 * /v1/exports/{export} is a genuine GET-by-id endpoint, so id is both
 * the row's identity and (paired with created_at) the list's cursor
 * column, needing no hidden-property trick.
 *
 * created_at stays snake_case in the PHP constructor for the same
 * cursor-column-name-matching reason LedgerEntryData's own created_at
 * property does: GET /v1/exports cursor-paginates in (created_at, id)
 * order, and Illuminate\Pagination\AbstractCursorPaginator reads the
 * ordering column's value off this transformed Data instance by literal
 * property name.
 */
#[TypeScript]
#[MapName(SnakeCaseMapper::class)]
class ExportData extends Data
{
    public function __construct(
        public string $id,
        public ExportType $type,
        public ExportStatus $status,
        public ExportParametersData $parameters,
        public ?int $rowCount,
        public ?string $failureCode,
        public ?string $completedAt,
        public string $created_at,
    ) {}

    public static function fromModel(Export $export): self
    {
        return new self(
            $export->id,
            $export->type,
            $export->status,
            ExportParametersData::from($export->parameters),
            $export->row_count,
            $export->failure_code,
            $export->completed_at !== null
                ? CarbonImmutable::instance($export->completed_at)->utc()->format('Y-m-d\TH:i:s\Z')
                : null,
            CarbonImmutable::instance($export->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
