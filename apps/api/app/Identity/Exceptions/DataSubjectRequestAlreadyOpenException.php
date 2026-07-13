<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised when App\Identity\Actions\CreateDataSubjectRequest's insert
 * collides with the data_subject_requests_open_per_customer_idx partial
 * unique index (stage-12 plan, Endpoints: "409 data_subject_request_
 * already_open ... surfaced from the partial unique index, mapped to a
 * problem document, never a 500"). The index is the invariant guard,
 * never a read-then-write existence check (CLAUDE.md): the database
 * decides who wins a concurrent request of the same type for the same
 * customer, and the loser's violation is translated here by index name,
 * mirroring App\Identity\Exceptions\RoleNameTakenException's own
 * precedent.
 */
final class DataSubjectRequestAlreadyOpenException extends RuntimeException implements HasErrorCode
{
    public static function make(): self
    {
        return new self('An open data subject request of this type already exists for this customer.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::DataSubjectRequestAlreadyOpen;
    }
}
