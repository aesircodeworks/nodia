<?php

namespace App\Reporting\Exceptions;

use App\Reporting\Enums\ExportType;
use RuntimeException;

/**
 * Raised by App\Reporting\Support\Export\ExportSourceRegistry::get() for
 * a type with no registered ExportSource (stage-11 plan, task 15:
 * "check_ins has no registered source until task 15 or 11 lands"). T13's
 * POST /v1/exports validates against the registry's has() before ever
 * reaching get(), so this exception firing in practice means an export
 * row's own type outlived its source (a deployment rolled back a
 * source, not a request the validator should have already rejected);
 * App\Reporting\Actions\BuildExport catches it like any other source
 * failure rather than letting it crash the queue worker.
 */
final class UnknownExportSourceException extends RuntimeException
{
    public static function for(ExportType $type): self
    {
        return new self("No ExportSource is registered for export type [{$type->value}].");
    }
}
