<?php

namespace App\Payments\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Sub-merchant onboarding lifecycle (stage-08c plan, Data model
 * "submerchant_accounts"; system-design 7.3, 19). Every transition is a
 * conditional UPDATE checked by affected-row count, never read-then-write.
 */
#[TypeScript]
enum SubmerchantStatus: string
{
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case ActionRequired = 'action_required';
    case Active = 'active';
    case Rejected = 'rejected';
    case Disabled = 'disabled';

    /**
     * Quarantine state for a gateway status string the adapter's status
     * map does not recognize (stage-08d plan, Slice 4). Never thrown,
     * never silently dropped: an unmapped raw status lands here so
     * support can investigate instead of the account either stalling
     * invisibly or being coerced into a wrong normalized state.
     */
    case NeedsReview = 'needs_review';
}
