<?php

namespace App\Inventory\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use App\Support\Problems\HasProblemExtensions;
use RuntimeException;

/**
 * Raised by App\Inventory\Actions\CreateHold when the purchase-counter
 * upsert guard (App\Inventory\Support\PurchaseCounters::increment,
 * stage-10 plan Data model "purchase_counters") affects zero rows for one
 * of the request's items: the customer has already reached that ticket
 * type's max_per_customer. The hold transaction rolls back (stage-10
 * plan, Endpoints "POST /v1/storefront/holds": "Counter guard affects
 * zero rows", code purchase_limit_exceeded, extension members
 * ticket_type_id and limit).
 */
final class PurchaseLimitExceededException extends RuntimeException implements HasErrorCode, HasProblemExtensions
{
    private function __construct(string $message, private readonly string $ticketTypeId, private readonly int $limit)
    {
        parent::__construct($message);
    }

    public static function forTicketType(string $ticketTypeId, int $limit): self
    {
        return new self(
            sprintf('Ticket type "%s" has reached its per-customer purchase limit of %d.', $ticketTypeId, $limit),
            $ticketTypeId,
            $limit,
        );
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PurchaseLimitExceeded;
    }

    /**
     * @return array<string, mixed>
     */
    public function problemExtensions(): array
    {
        return [
            'ticket_type_id' => $this->ticketTypeId,
            'limit' => $this->limit,
        ];
    }
}
