<?php

namespace App\Reporting\Http\Controllers;

use App\Reporting\Actions\CreateExport;
use App\Reporting\Data\CreateExportData;
use App\Reporting\Data\ExportData;
use App\Reporting\Data\ExportDownloadData;
use App\Reporting\Data\ExportParametersData;
use App\Reporting\Enums\ExportStatus;
use App\Reporting\Exceptions\ExportFailedException;
use App\Reporting\Exceptions\ExportNotFoundException;
use App\Reporting\Exceptions\ExportNotReadyException;
use App\Reporting\Models\Export;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Spatie\LaravelData\CursorPaginatedDataCollection;
use Spatie\LaravelData\Optional;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The export lifecycle surface (stage-11 plan, Endpoints; task 16),
 * every route behind reports.export (system-design 14.2: export files
 * carry customer PII, so the plan requires the export capability, not
 * the read-only reports.view).
 */
class ExportController
{
    public function store(Request $request, CreateExportData $data, CreateExport $create): JsonResponse
    {
        $staff = $request->user('staff');

        $parameters = $data->parameters instanceof Optional
            ? new ExportParametersData
            : $data->parameters;

        $export = $create($data->type, $parameters, (string) $staff?->getAuthIdentifier());

        return response()->json(ExportData::fromModel($export), 202);
    }

    /**
     * Cursor-paginated over (created_at, id), the same deterministic
     * newest-facts-tiebreak shape LedgerController::entries uses:
     * created_at is the caller-sortable column and id breaks ties.
     */
    public function index(Request $request): CursorPaginatedDataCollection
    {
        $exports = QueryBuilder::for(Export::class)
            ->allowedFilters(
                AllowedFilter::exact('type'),
                AllowedFilter::exact('status'),
            )
            ->allowedSorts('created_at')
            ->defaultSort('created_at')
            ->orderBy('id')
            ->cursorPaginate(min($request->integer('per_page', 15), 100))
            ->appends($request->query());

        return ExportData::collect($exports, CursorPaginatedDataCollection::class);
    }

    public function show(string $export): ExportData
    {
        $model = Export::query()->find($export) ?? throw ExportNotFoundException::forId($export);

        return ExportData::fromModel($model);
    }

    public function download(string $export): ExportDownloadData
    {
        $model = Export::query()->find($export) ?? throw ExportNotFoundException::forId($export);

        match ($model->status) {
            ExportStatus::Completed => null,
            ExportStatus::Failed => throw ExportFailedException::forId($export),
            ExportStatus::Pending, ExportStatus::Processing => throw ExportNotReadyException::forId($export),
        };

        $expiration = Date::now()->addMinutes(config()->integer('reporting.export_download_url_ttl_minutes'));

        // A completed export always has an attached file: App\Reporting\
        // Actions\BuildExport only transitions to completed after the
        // medialibrary attach succeeds. Guarded defensively rather than
        // asserted, the same posture every nullable-media read in this
        // codebase takes (App\EventCatalog\Data\StorefrontEventData's
        // own getFirstMedia() null check).
        $media = $model->getFirstMedia('export_file') ?? throw ExportNotReadyException::forId($export);

        return new ExportDownloadData(
            $media->getTemporaryUrl($expiration),
            CarbonImmutable::instance($expiration)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
