<?php

use App\Support\Problems\ErrorCode;

arch()->preset()->php();
arch()->preset()->security();

// The error code registry lives in App\Support\Problems per the Stage 1 plan,
// not in App\Enums where the preset expects enums.
arch()->preset()->laravel()->ignoring(ErrorCode::class);
