<?php

use App\Support\Money\CurrencyMismatchException;
use App\Support\Problems\ErrorCode;
use App\Support\Tenancy\InvalidTenantIdException;

arch()->preset()->php();
arch()->preset()->security();

// The error code registry lives in App\Support\Problems per the Stage 1 plan,
// not in App\Enums where the preset expects enums, and guard exceptions live
// with the value objects and wrappers they protect (App\Support\Money per
// ADR 018, App\Support\Tenancy), not in App\Exceptions where the preset
// expects Throwables. Eloquent models live in each bounded context's Models
// directory (system-design 8, ContextBoundariesTest), not in App\Models where
// the preset expects them.
arch()->preset()->laravel()->ignoring([
    ErrorCode::class,
    CurrencyMismatchException::class,
    InvalidTenantIdException::class,
    'App\Tenancy\Models',
]);
