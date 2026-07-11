<?php

namespace App\Payments\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum PaymentMethodConfirmation: string
{
    case Sync = 'sync';
    case Async = 'async';
}
