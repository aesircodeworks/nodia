<?php

use App\Reporting\Enums\ExportType;
use App\Reporting\Support\Export\CsvExportWriter;
use App\Reporting\Support\Export\ExportSource;
use App\Support\Money\Money;

/*
 * Stage-11 plan, TDD sequencing Slice 8 Unit tests: "each ExportSource
 * maps Data objects to CSV columns; the writer streams in cursor pages
 * and holds at most one page in memory."
 */

/**
 * A fake ExportSource whose pages() are given inline, so these tests
 * exercise only App\Reporting\Support\Export\CsvExportWriter's own
 * behavior, never a real cross-context Action.
 */
function fakeExportSource(iterable $pages, array $columns): ExportSource
{
    return new class($pages, $columns) implements ExportSource
    {
        public function __construct(
            private readonly iterable $pages,
            private readonly array $columns,
        ) {}

        public function type(): ExportType
        {
            return ExportType::Orders;
        }

        public function rules(): array
        {
            return [];
        }

        public function pages(string $tenantId, array $parameters): iterable
        {
            return $this->pages;
        }

        public function columns(): array
        {
            return $this->columns;
        }
    };
}

/**
 * @return list<string>
 */
function readCsvLines($stream): array
{
    rewind($stream);
    $lines = [];

    while (($row = fgetcsv($stream)) !== false) {
        $lines[] = $row;
    }

    return $lines;
}

it('writes the header row followed by every row across every page, mapped through the column extractors', function (): void {
    $rowA = (object) ['name' => 'alpha'];
    $rowB = (object) ['name' => 'beta'];
    $rowC = (object) ['name' => 'gamma'];

    $source = fakeExportSource(
        [[$rowA, $rowB], [$rowC]],
        ['label' => fn (object $row): string => strtoupper($row->name)],
    );

    $stream = fopen('php://memory', 'r+');
    $rowCount = (new CsvExportWriter)->write($source, 'tenant-1', [], $stream);

    expect($rowCount)->toBe(3)
        ->and(readCsvLines($stream))->toBe([
            ['label'],
            ['ALPHA'],
            ['BETA'],
            ['GAMMA'],
        ]);
});

it('writes only the header row when no page yields any rows', function (): void {
    $source = fakeExportSource([], ['label' => fn (object $row): string => 'unreachable']);

    $stream = fopen('php://memory', 'r+');
    $rowCount = (new CsvExportWriter)->write($source, 'tenant-1', [], $stream);

    expect($rowCount)->toBe(0)
        ->and(readCsvLines($stream))->toBe([['label']]);
});

it('renders money as an integer minor-units column plus a separate currency column, never a formatted float', function (): void {
    $row = (object) ['price' => Money::of(2_599, 'USD')];

    $source = fakeExportSource([[$row]], [
        'price_amount' => fn (object $row): int => $row->price->amount,
        'price_currency' => fn (object $row): string => $row->price->currency,
    ]);

    $stream = fopen('php://memory', 'r+');
    (new CsvExportWriter)->write($source, 'tenant-1', [], $stream);

    expect(readCsvLines($stream))->toBe([
        ['price_amount', 'price_currency'],
        ['2599', 'USD'],
    ]);
});

it('holds at most one page in memory: the next page is never requested before every row of the current one is written', function (): void {
    $log = [];

    $pages = (function () use (&$log): Generator {
        foreach ([['a', 'b'], ['c', 'd'], ['e']] as $index => $page) {
            $log[] = "request page {$index}";

            yield $page;
        }
    })();

    $source = fakeExportSource($pages, [
        'value' => function (string $row) use (&$log): string {
            $log[] = "write row {$row}";

            return $row;
        },
    ]);

    $stream = fopen('php://memory', 'r+');
    (new CsvExportWriter)->write($source, 'tenant-1', [], $stream);

    expect($log)->toBe([
        'request page 0',
        'write row a',
        'write row b',
        'request page 1',
        'write row c',
        'write row d',
        'request page 2',
        'write row e',
    ]);
});
