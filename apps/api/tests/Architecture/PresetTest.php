<?php

use App\Support\Money\CurrencyMismatchException;
use App\Support\Problems\ErrorCode;

arch()->preset()->php();
arch()->preset()->security();

// The error code registry lives in App\Support\Problems per the Stage 1 plan,
// not in App\Enums where the preset expects enums, and the Money guard
// exception lives with its value object in App\Support\Money per ADR 018,
// not in App\Exceptions where the preset expects Throwables.
arch()->preset()->laravel()->ignoring([ErrorCode::class, CurrencyMismatchException::class]);
