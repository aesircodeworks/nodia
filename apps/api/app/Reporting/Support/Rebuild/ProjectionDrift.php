<?php

namespace App\Reporting\Support\Rebuild;

/**
 * The result of comparing a projection's live table state against a
 * freshly replayed in-memory aggregate (stage-11 plan, task 13
 * --verify): missing rows the live table lacks, extra rows the live
 * table has with no matching replayed key, and rows present on both
 * sides whose value columns disagree.
 */
final readonly class ProjectionDrift
{
    public function __construct(
        public int $missingCount,
        public int $extraCount,
        public int $mismatchedCount,
    ) {}

    public function total(): int
    {
        return $this->missingCount + $this->extraCount + $this->mismatchedCount;
    }

    public function isClean(): bool
    {
        return $this->total() === 0;
    }
}
