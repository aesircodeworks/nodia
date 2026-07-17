<?php

namespace App\Support\Problems;

/**
 * Implemented by domain exceptions that map to a stable problem code, so
 * ProblemRenderer can render them without importing any bounded context.
 * The exception message becomes the problem detail; details are never
 * stable contract, only the code is.
 */
interface HasErrorCode
{
    public function errorCode(): ErrorCode;
}
