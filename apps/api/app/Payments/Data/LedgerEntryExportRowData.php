<?php

namespace App\Payments\Data;

use App\Payments\Models\LedgerEntry;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * One ledger entry's export facts (stage-11 plan, task 15/T12: the
 * `ledger_entries` export source). Plain internal class, never
 * serialized to the wire, mirroring App\Orders\Data\OrderExportRowData's
 * own posture; the wire-facing equivalent for GET /v1/ledger-entries is
 * the separate LedgerEntryData class, not this one.
 */
final class LedgerEntryExportRowData
{
    public function __construct(
        public readonly string $id,
        public readonly string $account,
        public readonly string $direction,
        public readonly Money $amount,
        public readonly string $referenceType,
        public readonly string $referenceId,
        public readonly string $createdAt,
    ) {}

    public static function fromModel(LedgerEntry $entry): self
    {
        return new self(
            $entry->id,
            $entry->account->value,
            $entry->direction->value,
            $entry->money,
            $entry->reference_type,
            $entry->reference_id,
            CarbonImmutable::instance($entry->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
