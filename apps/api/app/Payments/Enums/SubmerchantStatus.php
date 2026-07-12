<?php

namespace App\Payments\Enums;

/**
 * Sub-merchant onboarding lifecycle (stage-08c plan, Data model
 * "submerchant_accounts"; system-design 7.3, 19). Every transition is a
 * conditional UPDATE checked by affected-row count, never read-then-write.
 */
enum SubmerchantStatus: string
{
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case ActionRequired = 'action_required';
    case Active = 'active';
    case Rejected = 'rejected';
    case Disabled = 'disabled';
}
