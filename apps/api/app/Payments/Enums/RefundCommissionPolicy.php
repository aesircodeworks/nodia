<?php

namespace App\Payments\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Per-tenant policy for the platform commission on refunds
 * (system-design 7.3): returned gives the commission back to the buyer
 * proportionally with each refund, retained keeps it with the platform.
 */
#[TypeScript]
enum RefundCommissionPolicy: string
{
    case Returned = 'returned';
    case Retained = 'retained';
}
