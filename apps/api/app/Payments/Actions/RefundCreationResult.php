<?php

namespace App\Payments\Actions;

use App\Payments\Models\Refund;

final readonly class RefundCreationResult
{
    public function __construct(
        public Refund $refund,
        public string $orderId,
        public bool $replayed,
    ) {}
}
