<?php

namespace App\Reporting\Support\Export;

/**
 * Streams one ExportSource's rows to an open file handle as CSV
 * (stage-11 plan, task 15: "a streaming CSV writer that holds at most
 * one cursor page in memory"). The invariant is structural, not a
 * buffering trick this class has to manage itself: the outer foreach
 * over source->pages() never holds more than the current $page array
 * (whatever the source's own cursor-paginated Action yields for one
 * page), and every row in it is written and released before the next
 * page is even requested, since PHP generators (every current source's
 * pages() implementation) do not construct the next page until this
 * loop asks for it.
 *
 * The header row is always written, even for zero rows, so a completed
 * export with no matching rows is still a valid, openable CSV rather
 * than an empty file.
 */
final class CsvExportWriter
{
    /**
     * @param  array<string, mixed>  $parameters
     * @param  resource  $stream
     */
    public function write(ExportSource $source, string $tenantId, array $parameters, mixed $stream): int
    {
        $columns = $source->columns();

        $this->writeRow($stream, array_keys($columns));

        $rowCount = 0;

        foreach ($source->pages($tenantId, $parameters) as $page) {
            foreach ($page as $row) {
                $this->writeRow($stream, array_values(array_map(
                    static fn (callable $extract): string|int|float|bool|null => $extract($row),
                    $columns,
                )));

                $rowCount++;
            }
        }

        return $rowCount;
    }

    /**
     * @param  resource  $stream
     * @param  list<string|int|float|bool|null>  $fields
     */
    private function writeRow(mixed $stream, array $fields): void
    {
        // Every argument passed explicitly (PHP 8.4 deprecates relying on
        // fputcsv()'s own $escape default), values otherwise identical to
        // the function's defaults.
        fputcsv($stream, $fields, ',', '"', '\\');
    }
}
