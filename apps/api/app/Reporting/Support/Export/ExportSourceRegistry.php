<?php

namespace App\Reporting\Support\Export;

use App\Reporting\Enums\ExportType;
use App\Reporting\Exceptions\UnknownExportSourceException;

/**
 * Resolves an export type to its registered ExportSource (stage-11 plan,
 * task 15: "an ExportSourceRegistry resolving a type to its registered
 * source"). This is what T13's POST /v1/exports validates a requested
 * `type` against, not App\Reporting\Enums\ExportType directly: the enum
 * names every type the `exports.type` column can ever hold, but a type
 * only becomes creatable once a task registers its source here (task 15
 * ships `orders`; `tickets` and `ledger_entries` land with task 12,
 * `check_ins` with or after task 11's attendance projector). Populated
 * once, in App\Reporting\ReportingServiceProvider::boot(), and bound as
 * a container singleton so every consumer (the future POST endpoint,
 * App\Reporting\Actions\BuildExport) resolves the same registered set.
 */
final class ExportSourceRegistry
{
    /** @var array<string, ExportSource> keyed by ExportType::value */
    private array $sources = [];

    public function register(ExportSource $source): void
    {
        $this->sources[$source->type()->value] = $source;
    }

    public function has(ExportType $type): bool
    {
        return array_key_exists($type->value, $this->sources);
    }

    public function get(ExportType $type): ExportSource
    {
        return $this->sources[$type->value] ?? throw UnknownExportSourceException::for($type);
    }

    /**
     * @return list<ExportType> every type with a currently registered source, in registration order
     */
    public function registeredTypes(): array
    {
        return array_map(
            fn (ExportSource $source): ExportType => $source->type(),
            array_values($this->sources),
        );
    }
}
