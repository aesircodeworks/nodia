<?php

use App\Reporting\Enums\ExportType;
use App\Reporting\Exceptions\UnknownExportSourceException;
use App\Reporting\Support\Export\ExportSource;
use App\Reporting\Support\Export\ExportSourceRegistry;

/*
 * Stage-11 plan, task 15: "an ExportSourceRegistry resolving a type to
 * its registered source (this registry is what T13's POST validates
 * against, not the raw enum)". Unit tested against a fake source, not
 * the real OrdersExportSource, so this file exercises only the
 * registry's own resolution behavior.
 */

function fakeRegisteredSource(ExportType $type): ExportSource
{
    return new class($type) implements ExportSource
    {
        public function __construct(private readonly ExportType $exportType) {}

        public function type(): ExportType
        {
            return $this->exportType;
        }

        public function rules(): array
        {
            return [];
        }

        public function pages(string $tenantId, array $parameters): iterable
        {
            return [];
        }

        public function columns(): array
        {
            return [];
        }
    };
}

it('resolves a registered type back to the exact source instance that registered it', function (): void {
    $registry = new ExportSourceRegistry;
    $source = fakeRegisteredSource(ExportType::Orders);

    $registry->register($source);

    expect($registry->has(ExportType::Orders))->toBeTrue()
        ->and($registry->get(ExportType::Orders))->toBe($source)
        ->and($registry->registeredTypes())->toBe([ExportType::Orders]);
});

it('rejects a type with no registered source', function (): void {
    $registry = new ExportSourceRegistry;
    $registry->register(fakeRegisteredSource(ExportType::Orders));

    expect($registry->has(ExportType::CheckIns))->toBeFalse();

    expect(fn () => $registry->get(ExportType::CheckIns))
        ->toThrow(UnknownExportSourceException::class, 'No ExportSource is registered for export type [check_ins].');
});

it('reports no registered types at all before anything registers', function (): void {
    $registry = new ExportSourceRegistry;

    expect($registry->has(ExportType::Orders))->toBeFalse()
        ->and($registry->registeredTypes())->toBe([]);
});
