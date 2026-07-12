<?php

namespace App\CheckIn\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Input to the CheckEventAssignment Action (stage-09 plan, Authorization
 * semantics paragraph): the acting user, the target event, and the
 * capabilities their role carries. Capabilities travel by value, never a
 * role name or a Membership model, so the Orders-context signing-key
 * endpoints can authorize without importing CheckIn or Identity models
 * (boundary rule, system-design 3.1). Built by callers directly, never
 * bound from an HTTP request body, so it carries no rules().
 */
#[MapName(SnakeCaseMapper::class)]
class CheckEventAssignmentData extends Data
{
    /**
     * @param  list<string>  $capabilities
     */
    public function __construct(
        public string $userId,
        public string $eventId,
        public array $capabilities,
    ) {}
}
