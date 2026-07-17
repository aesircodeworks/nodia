<?php

namespace App\Inventory\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use App\Support\Problems\HasValidationErrors;
use RuntimeException;

/**
 * Raised by App\Inventory\Actions\CreateHold when the held-increment
 * guard (`sold + held + n <= quantity`, stage-06 plan Data model
 * "ticket_type_inventory") affects zero rows for one of the request's
 * items. Distinct from App\Inventory\Exceptions\
 * InsufficientInventoryException (the quantity-adjustment guard's own
 * exception, whose OpenAPI conflict schemas carry no errors member): this
 * one implements HasValidationErrors so the response identifies the
 * failing ticket_type_id in an extension member (stage-06 plan,
 * Endpoints: "insufficient_inventory ... responses identify the failing
 * ticket_type_id ... in an extension member").
 */
final class InsufficientHoldInventoryException extends RuntimeException implements HasErrorCode, HasValidationErrors
{
    private function __construct(string $message, private readonly string $ticketTypeId)
    {
        parent::__construct($message);
    }

    public static function forTicketType(string $ticketTypeId): self
    {
        return new self(
            sprintf('Ticket type "%s" does not have sufficient inventory for this hold.', $ticketTypeId),
            $ticketTypeId,
        );
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::InsufficientInventory;
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return [
            'ticket_type_id' => [$this->ticketTypeId],
        ];
    }
}
