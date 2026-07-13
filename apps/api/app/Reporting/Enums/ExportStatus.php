<?php

namespace App\Reporting\Enums;

/**
 * The export lifecycle (stage-11 plan, Data model "exports"). pending is
 * the only starting state; processing is the sole non-terminal
 * in-flight state; completed and failed are terminal. Every transition
 * is a conditional UPDATE on App\Reporting\Models\Export guarded on the
 * current status and checked by affected-row count, never
 * read-then-write (data-conventions, stage-11 plan, Data model "exports").
 */
enum ExportStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
