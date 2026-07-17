<?php

namespace App\Payments\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * initiated is the only non-terminal state; confirmed, failed, and
 * expired are terminal (stage-08a plan, Data model "payments"). Every
 * transition is a conditional UPDATE checked by affected-row count in
 * App\Payments\Actions, and the confirm edge additionally guards the
 * confirmation window so a payment past expires_at can never confirm,
 * even before the sweeper has run.
 */
#[TypeScript]
enum PaymentStatus: string
{
    case Initiated = 'initiated';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
    case Expired = 'expired';
}
