<?php

namespace App\Inventory\Data;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * App\Inventory\Actions\ExtendHold's input (stage-06 plan, TDD sequencing
 * Slice 4, task breakdown item 8: "the two Actions and their Data
 * objects"). No HTTP surface exists for extension in this stage; Stage
 * 8a's payment-window extension policy is the first caller
 * (system-design 7.4), constructing this directly rather than through
 * request validation.
 */
class ExtendHoldData extends Data
{
    public function __construct(
        public string $holdId,
        public CarbonImmutable $expiresAt,
    ) {}
}
