<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum HealthStatus: string
{
    case Ok = 'ok';
}
