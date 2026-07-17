<?php

namespace App\Payments\Data;

use App\Payments\Enums\LedgerAccount;
use App\Support\Money\Money;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Per-currency, per-account balance computed from the ledger
 * (system-design 7.3: the ledger is the source of truth for balances).
 * Signed by convention: credit-positive for tenant_net,
 * platform_commission, and gateway_fees; debit-positive for
 * gateway_receivable. This is the read Stage 8c reconciles payouts
 * against.
 */
#[TypeScript]
#[MapName(SnakeCaseMapper::class)]
class LedgerBalanceData extends Data
{
    public function __construct(
        public string $currency,
        public LedgerAccount $account,
        public Money $balance,
    ) {}
}
