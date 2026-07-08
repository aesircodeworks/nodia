<?php

namespace App\Http\Data;

use App\Enums\CheckResult;
use Spatie\LaravelData\Data;

class HealthChecksData extends Data
{
    public function __construct(
        public CheckResult $database,
        public CheckResult $redis,
        public CheckResult $storage,
    ) {}
}
