<?php

namespace App\Payments\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * pending and processing are the non-terminal states; completed and
 * failed are terminal (stage-08b plan, Data model "refunds"). pending
 * to processing guards the executor against double dispatch;
 * processing to completed and processing to failed guard duplicate
 * webhook or reconciliation outcomes. Every transition is a
 * conditional UPDATE checked by affected-row count.
 */
#[TypeScript]
enum RefundStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
