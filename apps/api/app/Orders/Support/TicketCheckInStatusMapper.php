<?php

namespace App\Orders\Support;

use App\Orders\Enums\QrVerificationOutcome;
use App\Orders\Enums\TicketStatus;

/**
 * The pure ticket-status branch of VerifyCheckInQr's mapping (stage-09
 * plan, Task 8: "ticket-status mapping (issued/canceled/refunded/unknown)").
 * Kept separate from the Action so the four branches are unit-testable
 * without a database round trip. Takes the raw stored status string
 * rather than the TicketStatus enum cast on purpose: VerifyCheckInQr
 * reads the column uncast so an out-of-enum value classifies as
 * TicketStatusUnknown instead of throwing a ValueError.
 */
final class TicketCheckInStatusMapper
{
    public static function classify(string $rawStatus): QrVerificationOutcome
    {
        return match (TicketStatus::tryFrom($rawStatus)) {
            TicketStatus::Issued => QrVerificationOutcome::Valid,
            TicketStatus::Canceled => QrVerificationOutcome::TicketCanceled,
            TicketStatus::Refunded => QrVerificationOutcome::TicketRefunded,
            null => QrVerificationOutcome::TicketStatusUnknown,
        };
    }
}
