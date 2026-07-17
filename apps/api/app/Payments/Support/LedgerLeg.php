<?php

namespace App\Payments\Support;

use App\Payments\Enums\LedgerAccount;
use App\Payments\Enums\LedgerDirection;
use App\Support\Money\Money;

final class LedgerLeg
{
    public function __construct(
        public readonly LedgerAccount $account,
        public readonly LedgerDirection $direction,
        public readonly Money $amount,
    ) {}
}
