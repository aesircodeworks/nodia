<?php

namespace App\Reporting\Actions;

use App\Reporting\Models\Export;
use App\Reporting\Support\Export\CsvExportWriter;
use App\Reporting\Support\Export\ExportSourceRegistry;
use Throwable;

/**
 * Claims and builds one export (stage-11 plan, task 15). Runs inside the
 * tenant transaction App\Reporting\Jobs\BuildExportJob opens: the claim,
 * the source's own reads, the completion or failure transition, and the
 * medialibrary attachment (which stamps its own tenant_id from the
 * active TenantContext, App\Support\Media\Models\Media) all commit
 * together.
 *
 * Export::claim() is both the exactly-one-worker guard and this action's
 * own existence check: a missing or already-claimed export simply
 * returns false and this method exits without side effects, exactly the
 * same "another worker owns it, exit cleanly" posture the outbox
 * delivery job takes on its own conditional mark (stage-04 plan).
 *
 * Any throwable from resolving the source, from the source's own pages()
 * iterator, or from attaching the file marks the export failed with a
 * single stable code rather than the exception's own message (stage-11
 * plan, Data model "exports": "a stable code surfaced on the resource,
 * never free text alone"); nothing is attached to the export in that
 * case, so a failure never leaves a completed-looking attachment behind.
 *
 * The CSV is built on a php://temp stream (spills to disk past PHP's own
 * threshold, never a path this class manages itself) and attached
 * through addMediaFromString(), mirroring
 * App\Orders\Jobs\GenerateTicketPdf's own posture rather than writing to
 * and cleaning up a real temp file.
 */
final readonly class BuildExport
{
    public const string SOURCE_FAILURE_CODE = 'export_source_failed';

    public function __construct(
        private ExportSourceRegistry $sources,
        private CsvExportWriter $writer,
    ) {}

    public function __invoke(string $exportId): void
    {
        if (! Export::claim($exportId)) {
            return;
        }

        try {
            $export = Export::query()->findOrFail($exportId);
            $source = $this->sources->get($export->type);

            $stream = fopen('php://temp', 'w+b');

            $rowCount = $this->writer->write($source, $export->tenant_id, $export->parameters, $stream);

            rewind($stream);
            $csv = stream_get_contents($stream);
            fclose($stream);

            $export->addMediaFromString($csv)
                ->usingFileName("export-{$export->id}.csv")
                ->toMediaCollection('export_file');

            Export::complete($exportId, $rowCount);
        } catch (Throwable $exception) {
            report($exception);

            Export::fail($exportId, self::SOURCE_FAILURE_CODE);
        }
    }
}
