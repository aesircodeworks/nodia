<?php

namespace App\Payments\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum LedgerDirection: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
