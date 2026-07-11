<?php

namespace App\Inventory\Data;

use Spatie\LaravelData\Data;

/**
 * App\Inventory\Actions\CommitHold's input (stage-06 plan, TDD sequencing
 * Slice 4, task breakdown item 8: "the two Actions and their Data
 * objects"). No HTTP surface exists for commit in this stage; Stage 7's
 * order-paid transaction is the first caller (system-design 7.1),
 * constructing this directly rather than through request validation.
 */
class CommitHoldData extends Data
{
    public function __construct(
        public string $holdId,
    ) {}
}
