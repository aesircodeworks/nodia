<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum CheckResult: string
{
    case Ok = 'ok';
    case Failed = 'failed';
}
