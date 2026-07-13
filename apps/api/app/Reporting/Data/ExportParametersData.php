<?php

namespace App\Reporting\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The exports.parameters jsonb shape (stage-11 plan, Data model
 * "exports"; Endpoints "CreateExportData: type, parameters (event_id
 * nullable, from and to nullable dates)"). Every field is nullable and
 * independently optional: which ones actually apply is a per-type
 * decision each registered App\Reporting\Support\Export\ExportSource
 * makes through its own rules() (for example
 * LedgerEntriesExportSource declares event_id prohibited), not a shape
 * this class enforces itself. Kept as a plain nested laravel-data object
 * (not a raw array) so it renders and validates like every other
 * request/response shape in this codebase, and so App\Reporting\Data\
 * ExportData can echo the same parameters back on the wire.
 */
#[TypeScript]
#[MapName(SnakeCaseMapper::class)]
class ExportParametersData extends Data
{
    public function __construct(
        #[TypeScriptOptional]
        public ?string $eventId = null,
        #[TypeScriptOptional]
        public ?string $from = null,
        #[TypeScriptOptional]
        public ?string $to = null,
    ) {}
}
