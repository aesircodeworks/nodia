<?php

namespace App\Payments\Data;

use App\Payments\Enums\LedgerAccount;
use App\Payments\Enums\LedgerDirection;
use App\Payments\Models\LedgerEntry;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The ledger entry wire shape (stage-08b plan, Endpoints
 * "GET /v1/ledger-entries").
 */
#[TypeScript]
#[MapName(SnakeCaseMapper::class)]
class LedgerEntryData extends Data
{
    public function __construct(
        public string $id,
        public LedgerAccount $account,
        public LedgerDirection $direction,
        public Money $amount,
        public string $referenceType,
        public string $referenceId,
        public string $createdAt,
    ) {}

    public static function fromModel(LedgerEntry $entry): self
    {
        return new self(
            $entry->id,
            $entry->account,
            $entry->direction,
            $entry->money,
            $entry->reference_type,
            $entry->reference_id,
            CarbonImmutable::instance($entry->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
