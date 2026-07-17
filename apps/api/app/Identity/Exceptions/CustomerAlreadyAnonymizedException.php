<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised when App\Identity\Actions\AnonymizeCustomer's conditional UPDATE
 * (anonymized_at is null) affects zero rows: the customer already holds
 * an anonymized_at, whether from an earlier completed erasure or a
 * concurrent one that committed first (stage-12 plan, Slice 1 Concurrency:
 * "the loser surfaces 409"). The database decides who wins, never a
 * read-then-write existence check (CLAUDE.md).
 */
final class CustomerAlreadyAnonymizedException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $customerId): self
    {
        return new self(sprintf('Customer "%s" is already anonymized.', $customerId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::CustomerAlreadyAnonymized;
    }
}
