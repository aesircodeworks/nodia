<?php

namespace App\Reporting\Enums;

/**
 * The export sources named in the stage-11 plan's Data model note on
 * exports: creation accepts only the types with a registered
 * App\Reporting\Support\ExportSource (task 15), so this enum is the full
 * set the column can ever hold, not the set POST /v1/exports currently
 * accepts. check_ins has no registered source until task 15 or 11 lands.
 */
enum ExportType: string
{
    case Orders = 'orders';
    case Tickets = 'tickets';
    case LedgerEntries = 'ledger_entries';
    case CheckIns = 'check_ins';
}
